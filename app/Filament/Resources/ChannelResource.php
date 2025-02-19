<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ChannelResource\Pages;
use App\Filament\Resources\ChannelResource\RelationManagers;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Epg;
use App\Models\Group;
use App\Models\Playlist;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Enums\Alignment;
use Filament\Tables;
use Filament\Tables\Columns\Layout\Grid;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ChannelResource extends Resource
{
    protected static ?string $model = Channel::class;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()
            ->where('user_id', auth()->id());
    }

    protected static ?string $navigationIcon = 'heroicon-o-film';

    protected static ?string $navigationGroup = 'Playlist';

    public static function getNavigationSort(): ?int
    {
        return 3;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema(self::getForm());
    }

    public static function table(Table $table): Table
    {
        return self::setupTable($table);
    }

    public static function setupTable(Table $table, $relationId = null): Table
    {
        $livewire = $table->getLivewire();
        return $table->persistFiltersInSession()
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label('Filters');
            })
            ->contentGrid(fn() => $livewire->isListLayout()
                ? null
                : [
                    'md' => 2,
                    'lg' => 3,
                    'xl' => 4,
                    '2xl' => 5,
                ])
            ->paginated([10, 25, 50, 100, 250])
            ->defaultPaginationPageOption(25)
            ->columns($livewire->isGridLayout() ? [
                Grid::make()
                    ->columns(1)
                    ->schema([
                        Tables\Columns\Layout\Grid::make()
                            ->columns(3)
                            ->schema([
                                Tables\Columns\Layout\Grid::make()
                                    ->columnSpan(1)
                                    ->columns(1)
                                    ->schema([
                                        Tables\Columns\ImageColumn::make('logo')
                                            ->toggleable()
                                            ->columnSpan(1)
                                            ->circular(),
                                        Tables\Columns\ToggleColumn::make('enabled')
                                            ->toggleable()
                                            ->tooltip('Toggle channel status')
                                            ->sortable(),
                                    ]),
                                Tables\Columns\Layout\Stack::make([
                                    Tables\Columns\TextColumn::make('title')
                                        ->description('Title', position: 'above')
                                        ->searchable()
                                        ->wrap()
                                        ->sortable(),
                                    Tables\Columns\TextColumn::make('name')
                                        ->description('Name', position: 'above')
                                        ->searchable()
                                        ->wrap()
                                        ->toggleable()
                                        ->sortable(),
                                ])->columnSpan(2)->alignment(Alignment::End)
                            ]),
                        Tables\Columns\Layout\Panel::make([
                            Tables\Columns\Layout\Grid::make()
                                ->columns(1)
                                ->schema([
                                    Tables\Columns\TextInputColumn::make('channel')
                                        ->rules(['numeric', 'min:0'])
                                        ->type('number')
                                        ->tooltip('Channel number')
                                        ->placeholder('Channel No.')
                                        ->toggleable()
                                        ->sortable(),
                                    Tables\Columns\TextInputColumn::make('shift')
                                        ->rules(['numeric', 'min:0'])
                                        ->type('number')
                                        ->tooltip('Shift')
                                        ->placeholder('Shift')
                                        ->toggleable()
                                        ->sortable(),
                                ])
                        ])->collapsible()->collapsed(false),
                        Tables\Columns\Layout\Panel::make([
                            Tables\Columns\Layout\Grid::make()
                                ->columns(1)
                                ->schema([
                                    Tables\Columns\TextColumn::make('group')
                                        ->hidden(fn() => $relationId)
                                        ->description('Group', position: 'above')
                                        ->toggleable()
                                        ->searchable()
                                        ->sortable(),
                                    Tables\Columns\TextColumn::make('epgChannel.name')
                                        ->label('EPG Channel')
                                        ->description('EPG Channel', position: 'above')
                                        ->toggleable()
                                        ->searchable()
                                        ->sortable(),
                                    Tables\Columns\TextColumn::make('url')
                                        ->url(fn($record): string => $record->url)
                                        ->limit(20)
                                        ->description('URL', position: 'above')
                                        ->searchable()
                                        ->toggleable(isToggledHiddenByDefault: true)
                                        ->sortable(),
                                    Tables\Columns\TextColumn::make('lang')
                                        ->description('Language', position: 'above')
                                        ->searchable()
                                        ->toggleable(isToggledHiddenByDefault: true)
                                        ->sortable(),
                                    Tables\Columns\TextColumn::make('country')
                                        ->description('Country', position: 'above')
                                        ->searchable()
                                        ->toggleable(isToggledHiddenByDefault: true)
                                        ->sortable(),
                                    Tables\Columns\TextColumn::make('playlist.name')
                                        ->description('Playlist', position: 'above')
                                        ->hidden(fn() => $relationId)
                                        ->numeric()
                                        ->toggleable()
                                        ->sortable(),
                                ])
                        ])->collapsible()->collapsed(true),
                    ])
            ] : [
                Tables\Columns\ImageColumn::make('logo')
                    ->toggleable()
                    ->circular(),
                Tables\Columns\TextColumn::make('title')
                    ->searchable()
                    ->wrap()
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->wrap()
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('stream_id')
                    ->searchable()
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\ToggleColumn::make('enabled')
                    ->toggleable()
                    ->tooltip('Toggle channel status')
                    ->sortable(),
                Tables\Columns\TextInputColumn::make('channel')
                    ->rules(['numeric', 'min:0'])
                    ->type('number')
                    ->placeholder('Channel No.')
                    ->tooltip('Channel number')
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextInputColumn::make('shift')
                    ->rules(['numeric', 'min:0'])
                    ->type('number')
                    ->placeholder('Shift')
                    ->tooltip('Shift')
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('group')
                    ->hidden(fn() => $relationId)
                    ->toggleable()
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('epgChannel.name')
                    ->label('EPG Channel')
                    ->toggleable()
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('url')
                    ->url(fn($record): string => $record->url)
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
                Tables\Columns\TextColumn::make('lang')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
                Tables\Columns\TextColumn::make('country')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
                Tables\Columns\TextColumn::make('playlist.name')
                    ->hidden(fn() => $relationId)
                    ->numeric()
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('playlist')
                    ->relationship('playlist', 'name')
                    ->hidden(fn() => $relationId)
                    ->multiple()
                    ->preload()
                    ->searchable(),
                Tables\Filters\SelectFilter::make('group')
                    ->relationship('group', 'name')
                    ->hidden(fn() => $relationId)
                    ->multiple()
                    ->preload()
                    ->searchable(),
                Tables\Filters\Filter::make('enabled')
                    ->label('Channel is enabled')
                    ->toggle()
                    ->query(function ($query) {
                        return $query->where('enabled', true);
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    // Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\BulkAction::make('add')
                        ->label('Add to custom playlist')
                        ->form([
                            Forms\Components\Select::make('playlist')
                                ->required()
                                ->label('Custom Playlist')
                                ->helperText('Select the custom playlist you would like to add the selected channel(s) to.')
                                ->options(CustomPlaylist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                                ->searchable(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $playlist = CustomPlaylist::findOrFail($data['playlist']);
                            $playlist->channels()->syncWithoutDetaching($records->pluck('id'));
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Channels added to custom playlist')
                                ->body('The selected channels have been added to the chosen custom playlist.')
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-play')
                        ->modalIcon('heroicon-o-play')
                        ->modalDescription('Add the selected channels(s) to the chosen custom playlist.')
                        ->modalSubmitActionLabel('Add now'),
                    Tables\Actions\BulkAction::make('move')
                        ->label('Move to group')
                        ->form([
                            Forms\Components\Select::make('playlist')
                                ->required()
                                ->live()
                                ->afterStateUpdated(function (Forms\Set $set) {
                                    $set('group', null);
                                })
                                ->label('Playlist')
                                ->helperText('Select a playlist - only channels in the selected playlist will be moved. Any channels selected from another playlist will be ignored.')
                                ->options(Playlist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                                ->searchable(),
                            Forms\Components\Select::make('group')
                                ->required()
                                ->live()
                                ->label('Group')
                                ->helperText(fn(Get $get) => $get('playlist') === null ? 'Select a playlist first...' : 'Select the group you would like to move the items to.')
                                ->options(fn(Get $get) => Group::where(['user_id' => auth()->id(), 'playlist_id' => $get('playlist')])->get(['name', 'id'])->pluck('name', 'id'))
                                ->searchable()
                                ->disabled(fn(Get $get) => $get('playlist') === null),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $filtered = $records->where('playlist_id', $data['playlist']);
                            $group = Group::findOrFail($data['group']);
                            foreach ($filtered as $record) {
                                $record->update([
                                    'group' => $group->name,
                                    'group_id' => $group->id,
                                ]);
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Channels moved to group')
                                ->body('The selected channels have been moved to the chosen group.')
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrows-right-left')
                        ->modalIcon('heroicon-o-arrows-right-left')
                        ->modalDescription('Move the selected channels(s) to the chosen group.')
                        ->modalSubmitActionLabel('Move now'),
                    Tables\Actions\BulkAction::make('map')
                        ->label('Map EPG to seleted')
                        ->form([
                            Forms\Components\Select::make('epg')
                                ->required()
                                ->label('EPG')
                                ->helperText('Select the EPG you would like to map from.')
                                ->options(Epg::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                                ->searchable(),
                            Forms\Components\Toggle::make('overwrite')
                                ->label('Overwrite previously mapped channels')
                                ->default(false),

                        ])
                        ->action(function (Collection $records, array $data): void {
                            app('Illuminate\Contracts\Bus\Dispatcher')
                                ->dispatch(new \App\Jobs\MapPlaylistChannelsToEpg(
                                    epg: (int)$data['epg'],
                                    channels: $records->pluck('id')->toArray(),
                                    force: $data['overwrite'],
                                ));
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('EPG to Channel mapping')
                                ->body('Mapping started, you will be notified when the process is complete.')
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-link')
                        ->modalIcon('heroicon-o-link')
                        ->modalDescription('Map the selected EPG to the selected channels(s).')
                        ->modalSubmitActionLabel('Map now'),
                    Tables\Actions\BulkAction::make('enable')
                        ->label('Enable selected')
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                $record->update([
                                    'enabled' => true,
                                ]);
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Selected channels enabled')
                                ->body('The selected channels have been enabled.')
                                ->send();
                        })
                        ->color('success')
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-check-circle')
                        ->modalIcon('heroicon-o-check-circle')
                        ->modalDescription('Enable the selected channels(s) now?')
                        ->modalSubmitActionLabel('Yes, enable now'),
                    Tables\Actions\BulkAction::make('disable')
                        ->label('Disable selected')
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                $record->update([
                                    'enabled' => false,
                                ]);
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Selected channels disabled')
                                ->body('The selected channels have been disabled.')
                                ->send();
                        })
                        ->color('danger')
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-x-circle')
                        ->modalIcon('heroicon-o-x-circle')
                        ->modalDescription('Disable the selected channels(s) now?')
                        ->modalSubmitActionLabel('Yes, disable now')
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListChannels::route('/'),
            //'create' => Pages\CreateChannel::route('/create'),
            //'edit' => Pages\EditChannel::route('/{record}/edit'),
        ];
    }

    public static function getForm(): array
    {
        return [
            // Customizable channel fields
            Forms\Components\Toggle::make('enabled')
                ->columnSpan('full')
                ->required(),
            Forms\Components\TextInput::make('logo')
                ->columnSpan('full')
                ->url(),
            Forms\Components\TextInput::make('channel')
                ->columnSpan(1)
                ->rules(['numeric', 'min:0']),
            Forms\Components\TextInput::make('shift')
                ->columnSpan(1)
                ->rules(['numeric', 'min:0']),
            Forms\Components\Select::make('epg_channel_id')
                ->label('EPG Channel')
                ->helperText('Select an associated EPG channel for this channel.')
                ->relationship('epgChannel', 'name')
                ->searchable()
                // ->preload()
                ->columnSpan(1),

            /*
             * Below fields are automatically populated/updated on Playlist sync.
             */

            // Forms\Components\TextInput::make('name')
            //     ->required()
            //     ->maxLength(255),
            // Forms\Components\TextInput::make('url')
            //     ->maxLength(255),
            // Forms\Components\TextInput::make('group')
            //     ->maxLength(255),
            // Forms\Components\TextInput::make('stream_id')
            //     ->maxLength(255),
            // Forms\Components\TextInput::make('lang')
            //     ->maxLength(255),
            // Forms\Components\TextInput::make('country')
            //     ->maxLength(255),
            // Forms\Components\Select::make('playlist_id')
            //     ->relationship('playlist', 'name')
            //     ->required(),
            // Forms\Components\Select::make('group_id')
            //     ->relationship('group', 'name'),
        ];
    }
}
