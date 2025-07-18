<?php

namespace App\Filament\Resources\ChannelResource\Pages;

use App\Filament\Exports\ChannelExporter;
use App\Filament\Imports\ChannelImporter;
use App\Filament\Resources\ChannelResource;
use App\Filament\Resources\EpgMapResource;
use App\Models\Channel;
use App\Models\Epg;
use App\Models\Playlist;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Hydrat\TableLayoutToggle\Concerns\HasToggleableTable;
use Illuminate\Support\Str;

class ListChannels extends ListRecords
{
    // use HasToggleableTable;

    protected static string $resource = ChannelResource::class;

    protected ?string $subheading = 'NOTE: Playlist channel output order is based on: 1 Sort order, 2 Channel no. and 3 Channel title - in that order. You can edit your Playlist output to auto sort as well, which will define the sort order based on the playlist order.';
    public function setPage($page, $pageName = 'page'): void
    {
        parent::setPage($page, $pageName);

        $this->dispatch('scroll-to-top');
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Create Custom Channel')
                ->modalHeading('New Custom Channel')
                ->modalDescription('NOTE: Custom channels need to be associated with a Playlist or Custom Playlist.')
                ->using(fn (array $data, string $model): Model => ChannelResource::createCustomChannel(
                    data: $data,
                    model: $model,
                ))
                ->slideOver(),
            Actions\ActionGroup::make([
                Actions\Action::make('merge')
                    ->label('Merge Same ID')
                    ->form([
                        Forms\Components\Select::make('playlist_id')
                            ->required()
                            ->label('Preferred Playlist')
                            ->options(Playlist::where('user_id', auth()->id())->pluck('name', 'id'))
                            ->live()
                            ->searchable()
                            ->helperText('Select a playlist to prioritize as the master during the merge process.'),
                        Forms\Components\Repeater::make('failover_playlists')
                            ->label('')
                            ->helperText('Select one or more playlists use as failover source(s).')
                            ->reorderable()
                            ->reorderableWithButtons()
                            ->orderColumn('sort')
                            ->simple(
                                Forms\Components\Select::make('playlist_failover_id')
                                    ->label('Failover Playlists')
                                    ->options(Playlist::where('user_id', auth()->id())->pluck('name', 'id'))
                                    ->searchable()
                                    ->required()
                            )
                            ->distinct()
                            ->columns(1)
                            ->addActionLabel('Add failover playlist')
                            ->columnSpanFull()
                            ->minItems(1)
                            ->defaultItems(1),
                        Forms\Components\Toggle::make('by_resolution')
                            ->label('Order by Resolution')
                            ->live()
                            ->helperText('When enabled, the highest resolution failover will be prioritized first. This will take longer to process as each matched stream will need to be assessed to determine resolution.')
                            ->default(false)
                    ])
                    ->action(function (array $data): void {
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new \App\Jobs\MergeChannels(
                                user: auth()->user(),
                                playlists: collect($data['failover_playlists']),
                                playlistId: $data['playlist_id'],
                                checkResolution: $data['by_resolution'] ?? false, // Sort failovers by resolution, or by playlist (default behavior)
                            ));
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Channel merge started')
                            ->body('Merging channels in the background. You will be notified once the process is complete.')
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrows-pointing-in')
                    ->modalIcon('heroicon-o-arrows-pointing-in')
                    ->modalDescription('Merge all channels with the same ID into a single channel with failover.')
                    ->modalSubmitActionLabel('Merge now'),
                Actions\Action::make('unmerge')
                    ->label('Unmerge Same ID')
                    ->action(function (): void {
                        $channels = Channel::where('user_id', auth()->id())->get();
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new \App\Jobs\UnmergeChannels($channels, auth()->user()));
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Channel unmerge started')
                            ->body('Unmerging channels in the background. You will be notified once the process is complete.')
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrows-pointing-out')
                    ->color('warning')
                    ->modalIcon('heroicon-o-arrows-pointing-out')
                    ->modalDescription('Unmerge all channels with the same ID, removing all failover relationships.')
                    ->modalSubmitActionLabel('Unmerge now'),
                Actions\Action::make('map')
                    ->label('Map EPG to Playlist')
                    ->form(EpgMapResource::getForm())
                    ->action(function (array $data): void {
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new \App\Jobs\MapPlaylistChannelsToEpg(
                                epg: (int)$data['epg_id'],
                                playlist: $data['playlist_id'],
                                force: $data['override'],
                                recurring: $data['recurring'],
                                settings: $data['settings'] ?? [],
                            ));
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('EPG to Channel mapping')
                            ->body('Channel mapping started, you will be notified when the process is complete.')
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-link')
                    ->color('gray')
                    ->modalIcon('heroicon-o-link')
                    ->modalDescription('Map the selected EPG to the selected Playlist channels.')
                    ->modalSubmitActionLabel('Map now'),

                Actions\Action::make('find-replace')
                    ->label('Find & Replace')
                    ->form([
                        Forms\Components\Toggle::make('all_playlists')
                            ->label('All Playlists')
                            ->live()
                            ->helperText('Apply find and replace to all playlists? If disabled, it will only apply to the selected playlist.')
                            ->default(true),
                        Forms\Components\Select::make('playlist')
                            ->label('Playlist')
                            ->required()
                            ->helperText('Select the playlist you would like to apply changes to.')
                            ->options(Playlist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                            ->hidden(fn(Get $get) => $get('all_playlists') === true)
                            ->searchable(),
                        Forms\Components\Toggle::make('use_regex')
                            ->label('Use Regex')
                            ->live()
                            ->helperText('Use regex patterns to find and replace. If disabled, will use direct string comparison.')
                            ->default(true),
                        Forms\Components\Select::make('column')
                            ->label('Column to modify')
                            ->options([
                                'title' => 'Channel Title',
                                'name' => 'Channel Name (tvg-name)',
                            ])
                            ->default('title')
                            ->required()
                            ->columnSpan(1),
                        Forms\Components\TextInput::make('find_replace')
                            ->label(fn(Get $get) =>  !$get('use_regex') ? 'String to replace' : 'Pattern to replace')
                            ->required()
                            ->placeholder(
                                fn(Get $get) => $get('use_regex')
                                    ? '^(US- |UK- |CA- )'
                                    : 'US -'
                            )->helperText(
                                fn(Get $get) => !$get('use_regex')
                                    ? 'This is the string you want to find and replace.'
                                    : 'This is the regex pattern you want to find. Make sure to use valid regex syntax.'
                            ),
                        Forms\Components\TextInput::make('replace_with')
                            ->label('Replace with (optional)')
                            ->placeholder('Leave empty to remove')

                    ])
                    ->action(function (Collection $records, array $data): void {
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new \App\Jobs\ChannelFindAndReplace(
                                user_id: auth()->id(), // The ID of the user who owns the content
                                all_playlists: $data['all_playlists'] ?? false,
                                playlist_id: $data['playlist'] ?? null,
                                use_regex: $data['use_regex'] ?? true,
                                column: $data['column'] ?? 'title',
                                find_replace: $data['find_replace'] ?? null,
                                replace_with: $data['replace_with'] ?? ''
                            ));
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Find & Replace started')
                            ->body('Find & Replace working in the background. You will be notified once the process is complete.')
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-magnifying-glass')
                    ->color('gray')
                    ->modalIcon('heroicon-o-magnifying-glass')
                    ->modalDescription('Select what you would like to find and replace in your channels list.')
                    ->modalSubmitActionLabel('Replace now'),

                Actions\Action::make('find-replace-reset')
                    ->label('Undo Find & Replace')
                    ->form([
                        Forms\Components\Toggle::make('all_playlists')
                            ->label('All Playlists')
                            ->live()
                            ->helperText('Apply reset to all playlists? If disabled, it will only apply to the selected playlist.')
                            ->default(false),
                        Forms\Components\Select::make('playlist')
                            ->required()
                            ->label('Playlist')
                            ->helperText('Select the playlist you would like to apply the reset to.')
                            ->options(Playlist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                            ->hidden(fn(Get $get) => $get('all_playlists') === true)
                            ->searchable(),
                        Forms\Components\Select::make('column')
                            ->label('Column to reset')
                            ->options([
                                'title' => 'Channel Title',
                                'name' => 'Channel Name (tvg-name)',
                            ])
                            ->default('title')
                            ->required()
                            ->columnSpan(1),
                    ])
                    ->action(function (Collection $records, array $data): void {
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new \App\Jobs\ChannelFindAndReplaceReset(
                                user_id: auth()->id(), // The ID of the user who owns the content
                                all_playlists: $data['all_playlists'] ?? false,
                                playlist_id: $data['playlist'] ?? null,
                                column: $data['column'] ?? 'title',
                            ));
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Find & Replace reset started')
                            ->body('Find & Replace reset working in the background. You will be notified once the process is complete.')
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->modalIcon('heroicon-o-arrow-uturn-left')
                    ->modalDescription('Reset Find & Replace results back to playlist defaults. This will remove any custom values set in the selected column.')
                    ->modalSubmitActionLabel('Reset now'),

                Actions\ImportAction::make()
                    ->importer(ChannelImporter::class)
                    ->label('Import Channels')
                    ->icon('heroicon-m-arrow-down-tray')
                    ->color('primary')
                    ->modalDescription('Import channels from a CSV or XLSX file.'),
                Actions\ExportAction::make()
                    ->exporter(ChannelExporter::class)
                    ->label('Export Channels')
                    ->icon('heroicon-m-arrow-up-tray')
                    ->color('primary')
                    ->modalDescription('Export channels to a CSV or XLSX file. NOTE: Only enabled channels will be exported.')
                    ->columnMapping(false)
                    ->modifyQueryUsing(function ($query, array $options) {
                        // For now, only allow exporting enabled channels
                        return $query->where([
                            ['playlist_id', $options['playlist']],
                            ['enabled', true],
                        ]);
                        // return $query->where('playlist_id', $options['playlist'])
                        //     ->when($options['enabled'], function ($query, $enabled) {
                        //         return $query->where('enabled', $enabled);
                        //     });
                    })
            ])->button()->label('Actions'),
        ];
    }

    public function getTabs(): array
    {
        return self::setupTabs();
    }

    public static function setupTabs($relationId = null): array
    {
        $where = [
            ['user_id', auth()->id()],
            // ...
        ];

        // Change count based on view
        $totalCount = Channel::query()
            ->when($relationId, function ($query, $relationId) {
                return $query->where('group_id', $relationId);
            })->count();
        $enabledCount = Channel::query()->where([...$where, ['enabled', true]])
            ->when($relationId, function ($query, $relationId) {
                return $query->where('group_id', $relationId);
            })->count();
        $disabledCount = Channel::query()->where([...$where, ['enabled', false]])
            ->when($relationId, function ($query, $relationId) {
                return $query->where('group_id', $relationId);
            })->count();
        $customCount = Channel::query()->where([...$where, ['is_custom', true]])
            ->when($relationId, function ($query, $relationId) {
                return $query->where('group_id', $relationId);
            })->count();

        // Return tabs
        return [
            'all' => Tab::make('All Channels')
                ->badge($totalCount),
            'enabled' => Tab::make('Enabled')
                // ->icon('heroicon-m-check')
                ->badgeColor('success')
                ->modifyQueryUsing(fn($query) => $query->where('enabled', true))
                ->badge($enabledCount),
            'disabled' => Tab::make('Disabled')
                // ->icon('heroicon-m-x-mark')
                ->badgeColor('danger')
                ->modifyQueryUsing(fn($query) => $query->where('enabled', false))
                ->badge($disabledCount),
            'custom' => Tab::make('Custom')
                // ->icon('heroicon-m-x-mark')
                ->modifyQueryUsing(fn($query) => $query->where('is_custom', true))
                ->badge($customCount),
        ];
    }

    /**
     * @deprecated Override the `table()` method to configure the table.
     */
    protected function getTableQuery(): ?Builder
    {
        return static::getResource()::getEloquentQuery()
            ->where('user_id', auth()->id());
    }
}
