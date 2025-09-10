<?php

namespace App\Filament\Resources\CustomPlaylistResource\RelationManagers;

use App\Filament\Resources\ChannelResource;
use App\Filament\BulkActions\HandlesSourcePlaylist;
use App\Models\Channel;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Columns\SpatieTagsColumn;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Tags\Tag;

class ChannelsRelationManager extends RelationManager
{
    use HandlesSourcePlaylist;
    protected static string $relationship = 'channels';

    protected static ?string $label = 'Live Channels';

    protected static ?string $pluralLabel = 'Live Channels';

    protected static ?string $title = 'Live Channels';

    protected static ?string $navigationLabel = 'Live Channels';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([]);
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return ChannelResource::infolist($infolist);
    }

    public function table(Table $table): Table
    {
        $ownerRecord = $this->ownerRecord;

        $groupColumn = SpatieTagsColumn::make('tags')
            ->label('Playlist Group')
            ->type($ownerRecord->uuid)
            ->toggleable()->searchable(query: function (Builder $query, string $search) use ($ownerRecord): Builder {
                return $query->whereHas('tags', function (Builder $query) use ($search, $ownerRecord) {
                    $query->where('tags.type', $ownerRecord->uuid);

                    // Cross-database compatible JSON search
                    $connection = $query->getConnection();
                    $driver = $connection->getDriverName();

                    switch ($driver) {
                        case 'pgsql':
                            // PostgreSQL uses ->> operator for JSON
                            $query->whereRaw('LOWER(tags.name->>\'$\') LIKE ?', ['%'.strtolower($search).'%']);
                            break;
                        case 'mysql':
                            // MySQL uses JSON_EXTRACT
                            $query->whereRaw('LOWER(JSON_EXTRACT(tags.name, "$")) LIKE ?', ['%'.strtolower($search).'%']);
                            break;
                        case 'sqlite':
                            // SQLite uses json_extract
                            $query->whereRaw('LOWER(json_extract(tags.name, "$")) LIKE ?', ['%'.strtolower($search).'%']);
                            break;
                        default:
                            // Fallback - try to search the JSON as text
                            $query->where(DB::raw('LOWER(CAST(tags.name AS TEXT))'), 'LIKE', '%'.strtolower($search).'%');
                            break;
                    }
                });
            })
            ->sortable(query: function (Builder $query, string $direction) use ($ownerRecord): Builder {
                $connection = $query->getConnection();
                $driver = $connection->getDriverName();

                // Build the ORDER BY clause based on database type
                $orderByClause = match ($driver) {
                    'pgsql' => 'tags.name->>\'$\'',
                    'mysql' => 'JSON_EXTRACT(tags.name, "$")',
                    'sqlite' => 'json_extract(tags.name, "$")',
                    default => 'CAST(tags.name AS TEXT)'
                };

                return $query
                    ->leftJoin('taggables', function ($join) {
                        $join->on('channels.id', '=', 'taggables.taggable_id')
                            ->where('taggables.taggable_type', '=', Channel::class);
                    })
                    ->leftJoin('tags', function ($join) use ($ownerRecord) {
                        $join->on('taggables.tag_id', '=', 'tags.id')
                            ->where('tags.type', '=', $ownerRecord->uuid);
                    })
                    ->orderByRaw("{$orderByClause} {$direction}")
                    ->select('channels.*', DB::raw("{$orderByClause} as tag_name_sort"))
                    ->distinct();
            });
        $defaultColumns = ChannelResource::getTableColumns(showGroup: true, showPlaylist: false);

        // Inject the custom group column after the group column
        array_splice($defaultColumns, 13, 0, [$groupColumn]);

        $defaultColumns[] = SelectColumn::make('playlist_id')
            ->label('Parent Playlist')
            ->getStateUsing(fn (Channel $record) => $record->playlist_id)
            ->options(fn (Channel $record) => $this->playlistOptions($record))
            ->disabled(fn (Channel $record) => count($this->playlistOptions($record)) <= 1)
            ->selectablePlaceholder(false)
            ->updateStateUsing(fn ($state) => $state)
            ->afterStateUpdated(fn ($state, Channel $record) => $this->changeSourcePlaylist($record, (int) $state))
            ->toggleable()
            ->sortable();

        return $table->persistFiltersInSession()
            ->persistFiltersInSession()
            ->persistSortInSession()
            ->recordTitleAttribute('title')
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label('Filters');
            })
            ->modifyQueryUsing(function (Builder $query) {
                $query->with(['tags', 'epgChannel', 'playlist'])
                    ->withCount(['failovers'])
                    ->where('is_vod', false); // Only show live channels
            })
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->columns($defaultColumns)
            ->filters([
                ...ChannelResource::getTableFilters(showPlaylist: true),
                Tables\Filters\SelectFilter::make('playlist_group')
                    ->label('Custom Group')
                    ->options(function () use ($ownerRecord) {
                        return $ownerRecord->tags()
                            ->where('type', $ownerRecord->uuid)
                            ->get()
                            ->mapWithKeys(fn ($tag) => [$tag->getAttributeValue('name') => $tag->getAttributeValue('name')])
                            ->toArray();
                    })
                    ->query(function (Builder $query, array $data) use ($ownerRecord): Builder {
                        if (empty($data['values'])) {
                            return $query;
                        }

                        return $query->where(function ($query) use ($data, $ownerRecord) {
                            foreach ($data['values'] as $groupName) {
                                $query->orWhereHas('tags', function ($tagQuery) use ($groupName, $ownerRecord) {
                                    $tagQuery->where('type', $ownerRecord->uuid)
                                        ->where('name->en', $groupName);
                                });
                            }
                        });
                    })
                    ->multiple()
                    ->searchable(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Create Custom Channel')
                    ->form(ChannelResource::getForm(customPlaylist: $ownerRecord))
                    ->modalHeading('New Custom Channel')
                    ->modalDescription('NOTE: Custom channels need to be associated with a Playlist or Custom Playlist.')
                    ->using(fn (array $data, string $model): Model => ChannelResource::createCustomChannel(
                        data: $data,
                        model: $model,
                    ))
                    ->slideOver(),
                Tables\Actions\AttachAction::make()
                    ->form(fn (Tables\Actions\AttachAction $action): array => [
                        $action
                            ->getRecordSelect()
                            ->getSearchResultsUsing(function (string $search) {
                                $searchLower = strtolower($search);
                                $channels = Auth::user()->channels()
                                    ->withoutEagerLoads()
                                    ->with('playlist')
                                    ->where('is_vod', false) // Only live channels
                                    ->where(function ($query) use ($searchLower) {
                                        $query->whereRaw('LOWER(title) LIKE ?', ["%{$searchLower}%"])
                                            ->orWhereRaw('LOWER(title_custom) LIKE ?', ["%{$searchLower}%"])
                                            ->orWhereRaw('LOWER(name) LIKE ?', ["%{$searchLower}%"])
                                            ->orWhereRaw('LOWER(name_custom) LIKE ?', ["%{$searchLower}%"])
                                            ->orWhereRaw('LOWER(stream_id) LIKE ?', ["%{$searchLower}%"])
                                            ->orWhereRaw('LOWER(stream_id_custom) LIKE ?', ["%{$searchLower}%"]);
                                    })
                                    ->limit(50)
                                    ->get();

                                // Create options array
                                $options = [];
                                foreach ($channels as $channel) {
                                    $displayTitle = $channel->title_custom ?: $channel->title;
                                    $playlistName = $channel->getEffectivePlaylist()->name ?? 'Unknown';
                                    $options[$channel->id] = "{$displayTitle} [{$playlistName}]";
                                }

                                return $options;
                            })
                            ->getOptionLabelFromRecordUsing(function ($record) {
                                $displayTitle = $record->title_custom ?: $record->title;
                                $playlistName = $record->getEffectivePlaylist()->name ?? 'Unknown';
                                $options[$record->id] = "{$displayTitle} [{$playlistName}]";

                                return "{$displayTitle} [{$playlistName}]";
                            }),
                    ]),

                // Advanced attach when adding pivot values:
                // Tables\Actions\AttachAction::make()->form(fn(Tables\Actions\AttachAction $action): array => [
                //     $action->getRecordSelect(),
                //     Forms\Components\TextInput::make('title')
                //         ->label('Title')
                //         ->required(),
                // ]),
            ])
            ->actions([
                Tables\Actions\DetachAction::make()
                    ->color('danger')
                    ->button()->hiddenLabel()
                    ->icon('heroicon-o-x-circle')
                    ->size('sm'),
                ...ChannelResource::getTableActions(),
            ], position: Tables\Enums\ActionsPosition::BeforeCells)
            ->bulkActions([
                ...ChannelResource::getTableBulkActions(addToCustom: false),
                Tables\Actions\DetachBulkAction::make()->color('danger'),
                Tables\Actions\BulkAction::make('add_to_group')
                    ->label('Add to custom group')
                    ->form([
                        Forms\Components\Select::make('group')
                            ->label('Select group')
                            ->options(
                                Tag::where('type', $ownerRecord->uuid)
                                    ->pluck('name', 'name')
                            )
                            ->required(),
                    ])
                    ->action(function (Collection $records, $data) use ($ownerRecord): void {
                        foreach ($records as $record) {
                            $record->syncTagsWithType([$data['group']], $ownerRecord->uuid);
                        }
                    })->after(function () {
                        FilamentNotification::make()
                            ->success()
                            ->title('Added to group')
                            ->body('The selected channels have been added to the custom group.')
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation()
                    ->icon('heroicon-o-squares-plus')
                    ->modalIcon('heroicon-o-squares-plus')
                    ->modalDescription('Add to group')
            ->modalSubmitActionLabel('Yes, add to group'),
            ]);
    }

    protected function playlistOptions(Channel $record): array
    {
        [$groups] = self::getSourcePlaylistData(collect([$record]), 'channels', 'source_id');

        if ($groups->isEmpty()) {
            return [$record->playlist_id => $record->playlist?->name];
        }

        $group = $groups->first();
        $options = self::availablePlaylistsForGroup($this->ownerRecord->id, $group, 'channels', 'source_id');

        return $options->put($record->playlist_id, $record->playlist?->name)->toArray();
    }

    protected function changeSourcePlaylist(Channel $record, int $playlistId): void
    {
        if ($playlistId === $record->playlist_id) {
            return;
        }

        $replacement = Channel::where('playlist_id', $playlistId)
            ->where('source_id', $record->source_id)
            ->first();

        if (! $replacement) {
            FilamentNotification::make()
                ->title('Channel not found in selected playlist')
                ->danger()
                ->send();

            return;
        }

        $this->ownerRecord->channels()->detach($record->id);
        $this->ownerRecord->channels()->attach($replacement->id);

        FilamentNotification::make()
            ->title('Parent playlist updated')
            ->success()
            ->send();

        $this->dispatch('refresh');
    }
}
