<?php

namespace App\Filament\Resources;

use App\Enums\Status;
use App\Filament\Resources\PlaylistResource\Pages;
use App\Filament\Resources\PlaylistResource\RelationManagers;
use App\Models\Playlist;
use App\Rules\CheckIfUrlOrLocalPath;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\Column;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use RyanChandler\FilamentProgressColumn\ProgressColumn;
use App\Facades\PlaylistUrlFacade;
use App\Filament\Resources\PlaylistSyncStatusResource\Pages\CreatePlaylistSyncStatus;
use App\Filament\Resources\PlaylistSyncStatusResource\Pages\EditPlaylistSyncStatus;
use App\Filament\Resources\PlaylistSyncStatusResource\Pages\ListPlaylistSyncStatuses;
use App\Filament\Resources\PlaylistSyncStatusResource\Pages\ViewPlaylistSyncStatus;
use App\Livewire\EpgViewer;
use App\Livewire\MediaFlowProxyUrl;
use App\Livewire\PlaylistEpgUrl;
use App\Livewire\PlaylistInfo;
use App\Livewire\PlaylistM3uUrl;
use App\Livewire\XtreamApiInfo;
use App\Models\SourceGroup;
use App\Services\EpgCacheService;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Redis;
use Filament\Actions;

class PlaylistResource extends Resource
{
    protected static ?string $model = Playlist::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getRecordTitle(?Model $record): string|null|Htmlable
    {
        return $record->name;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'url'];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()
            ->where('user_id', \Illuminate\Support\Facades\Auth::id());
    }

    protected static ?string $navigationGroup = 'Playlist';

    public static function getNavigationSort(): ?int
    {
        return 0;
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
        return $table->persistFiltersInSession()
            ->persistSortInSession()
            ->modifyQueryUsing(function (Builder $query) {
                $query->withCount('enabled_live_channels')
                    ->withCount('enabled_vod_channels')
                    ->withCount('enabled_series')
                    ->orderByRaw('COALESCE(parent_playlist_id, id), parent_playlist_id IS NOT NULL, name');
            })
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->searchable()
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn($state, Playlist $record) => $record->parent_playlist_id ? str_repeat('&nbsp;', 4).'↳ '.$state : $state)
                    ->html(),
                Tables\Columns\TextColumn::make('pairedPlaylist.name')
                    ->label('Paired With')
                    ->toggleable()
                    ->sortable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('parentPlaylist.name')
                    ->label('Parent')
                    ->toggleable()
                    ->sortable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('url')
                    ->label('Playlist URL')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->searchable(),
                Tables\Columns\TextColumn::make('available_streams')
                    ->label('Streams')
                    ->toggleable()
                    ->formatStateUsing(fn(int $state): string => $state === 0 ? '∞' : (string)$state)
                    ->tooltip('Total streams available for this playlist (∞ indicates no limit)')
                    ->description(fn(Playlist $record): string => "Active: " . (int) Redis::get("active_streams:{$record->id}") ?? 0)
                    ->sortable(),
                Tables\Columns\TextColumn::make('groups_count')
                    ->label('Groups')
                    ->counts('groups')
                    ->toggleable()
                    ->sortable(),
                // Tables\Columns\TextColumn::make('channels_count')
                //     ->label('Channels')
                //     ->counts('channels')
                //     ->description(fn(Playlist $record): string => "Enabled: {$record->enabled_channels_count}")
                //     ->toggleable()
                //     ->sortable(),
                Tables\Columns\TextColumn::make('live_channels_count')
                    ->label('Live')
                    ->counts('live_channels')
                    ->description(fn(Playlist $record): string => "Enabled: {$record->enabled_live_channels_count}")
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('vod_channels_count')
                    ->label('VOD')
                    ->counts('vod_channels')
                    ->description(fn(Playlist $record): string => "Enabled: {$record->enabled_vod_channels_count}")
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('series_count')
                    ->label('Series')
                    ->counts('series')
                    ->description(fn(Playlist $record): string => "Enabled: {$record->enabled_series_count}")
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->sortable()
                    ->badge()
                    ->toggleable()
                    ->color(fn(Status $state) => $state->getColor()),
                ProgressColumn::make('progress')
                    ->label('Channel Sync')
                    ->sortable()
                    ->poll(fn($record) => $record->status === Status::Processing || $record->status === Status::Pending ? '3s' : null)
                    ->toggleable(),
                ProgressColumn::make('series_progress')
                    ->label('Series Sync')
                    ->sortable()
                    ->poll(fn($record) => $record->status === Status::Processing || $record->status === Status::Pending ? '3s' : null)
                    ->toggleable(),
                Tables\Columns\ToggleColumn::make('enable_proxy')
                    ->label('Proxy')
                    ->toggleable()
                    ->tooltip('Toggle proxy status')
                    ->sortable(),
                Tables\Columns\ToggleColumn::make('auto_sync')
                    ->label('Auto Sync')
                    ->toggleable()
                    ->tooltip('Toggle auto-sync status')
                    ->sortable(),
                Tables\Columns\TextColumn::make('synced')
                    ->label('Last Synced')
                    ->since()
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('sync_interval')
                    ->label('Interval')
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('sync_time')
                    ->label('Sync Time')
                    ->formatStateUsing(fn(string $state): string => gmdate('H:i:s', (int)$state))
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('exp_date')
                    ->label('Expiry Date')
                    ->getStateUsing(function ($record) {
                        if ($record->xtream_status) {
                            try {
                                if ($record->xtream_status['user_info']['exp_date'] ?? false) {
                                    $expires = Carbon::createFromTimestamp($record->xtream_status['user_info']['exp_date']);
                                    return $expires->toDayDateTimeString();
                                }
                            } catch (\Exception $e) {
                            }
                        }
                        return 'N/A';
                    })
                    ->toggleable(isToggledHiddenByDefault: true),
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
                //
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('process')
                        ->label('Sync and Process')
                        ->icon('heroicon-o-arrow-path')
                        ->action(function ($record) {
                            $record->update([
                                'status' => Status::Processing,
                                'progress' => 0,
                            ]);
                            app('Illuminate\Contracts\Bus\Dispatcher')
                                ->dispatch(new \App\Jobs\ProcessM3uImport($record, force: true));
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Playlist is processing')
                                ->body('Playlist is being processed in the background. Depending on the size of your playlist, this may take a while. You will be notified on completion.')
                                ->duration(10000)
                                ->send();
                        })
                        ->disabled(fn($record): bool => $record->status === Status::Processing)
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-path')
                        ->modalIcon('heroicon-o-arrow-path')
                        ->modalDescription('Process playlist now?')
                        ->modalSubmitActionLabel('Yes, process now'),
                    Tables\Actions\Action::make('process_series')
                        ->label('Fetch Series Metadata')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->action(function ($record) {
                            $record->update([
                                'status' => Status::Processing,
                                'series_progress' => 0,
                            ]);
                            app('Illuminate\Contracts\Bus\Dispatcher')
                                ->dispatch(new \App\Jobs\ProcessM3uImportSeries($record, force: true));
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Playlist is fetching metadata for Series')
                                ->body('Playlist Series are being processed in the background. Depending on the number of enabled Series, this may take a while. You will be notified on completion.')
                                ->duration(10000)
                                ->send();
                        })
                        ->disabled(fn($record): bool => $record->status === Status::Processing)
                        ->hidden(fn($record): bool => !$record->xtream)
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-down-tray')
                        ->modalIcon('heroicon-o-arrow-down-tray')
                        ->modalDescription('Fetch Series metadata for this playlist now? Only enabled Series will be included.')
                        ->modalSubmitActionLabel('Yes, process now'),
                    Tables\Actions\Action::make('process_vod')
                        ->label('Fetch VOD Metadata')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->action(function ($record) {
                            $record->update([
                                'status' => Status::Processing,
                                'progress' => 0,
                            ]);
                            app('Illuminate\Contracts\Bus\Dispatcher')
                                ->dispatch(new \App\Jobs\ProcessVodChannels(playlist: $record));
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Playlist is fetching metadata for VOD channels')
                                ->body('Playlist VOD channels are being processed in the background. Depending on the number of enabled VOD channels, this may take a while. You will be notified on completion.')
                                ->duration(10000)
                                ->send();
                        })
                        ->disabled(fn($record): bool => $record->status === Status::Processing)
                        ->hidden(fn($record): bool => !$record->xtream)
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-down-tray')
                        ->modalIcon('heroicon-o-arrow-down-tray')
                        ->modalDescription('Fetch VOD metadata for this playlist now? Only enabled VOD channels will be included.')
                        ->modalSubmitActionLabel('Yes, process now'),
                    Tables\Actions\Action::make('Download M3U')
                        ->label('Download M3U')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->url(fn($record) => PlaylistUrlFacade::getUrls($record)['m3u'])
                        ->openUrlInNewTab(),
                    EpgCacheService::getEpgTableAction(),
                    Tables\Actions\Action::make('HDHomeRun URL')
                        ->label('HDHomeRun URL')
                        ->icon('heroicon-o-arrow-top-right-on-square')
                        ->url(fn($record) => PlaylistUrlFacade::getUrls($record)['hdhr'])
                        ->openUrlInNewTab(),
                    Tables\Actions\Action::make('Duplicate')
                        ->label('Duplicate')
                        ->form([
                            Forms\Components\TextInput::make('name')
                                ->label('Playlist name')
                                ->required()
                                ->helperText('This will be the name of the duplicated playlist.'),
                        ])
                        ->action(function ($record, $data) {
                            app('Illuminate\Contracts\Bus\Dispatcher')
                                ->dispatch(new \App\Jobs\DuplicatePlaylist($record, $data['name']));
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Playlist is being duplicated')
                                ->body('Playlist is being duplicated in the background. You will be notified on completion.')
                                ->duration(3000)
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-document-duplicate')
                        ->modalIcon('heroicon-o-document-duplicate')
                        ->modalDescription('Duplicate playlist now?')
                        ->modalSubmitActionLabel('Yes, duplicate now'),
                    Tables\Actions\Action::make('Sync Logs')
                        ->label('View Sync Logs')
                        ->color('gray')
                        ->icon('heroicon-m-arrows-right-left')
                        ->url(
                            fn(Playlist $record): string => PlaylistResource::getUrl(
                                name: 'playlist-sync-statuses.index',
                                parameters: [
                                    'parent' => $record->id,
                                ]
                            )
                        ),
                    Tables\Actions\Action::make('reset')
                        ->label('Reset status')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->color('warning')
                        ->action(function ($record) {
                            $record->update([
                                'status' => Status::Pending,
                                'processing' => false,
                                'progress' => 0,
                                'series_progress' => 0,
                                'channels' => 0,
                                'synced' => null,
                                'errors' => null,
                            ]);
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Playlist status reset')
                                ->body('Playlist status has been reset.')
                                ->duration(3000)
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->modalIcon('heroicon-o-arrow-uturn-left')
                        ->modalDescription('Reset playlist status so it can be processed again. Only perform this action if you are having problems with the playlist syncing.')
                        ->modalSubmitActionLabel('Yes, reset now'),
                    Tables\Actions\Action::make('reset_active_count')
                        ->label('Reset active count')
                        ->icon('heroicon-o-numbered-list')
                        ->color('warning')
                        ->action(fn($record) => Redis::set("active_streams:{$record->id}", 0))->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Active stream count reset')
                                ->body('Playlist active stream count has been reset.')
                                ->duration(3000)
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->modalIcon('heroicon-o-numbered-list')
                        ->modalDescription('Reset playlist active streams count. Proceed with caution as this could lead to an incorrect count if there are streams currently running.')
                        ->modalSubmitActionLabel('Yes, reset now'),
                    Tables\Actions\Action::make('purge_series')
                        ->label('Purge Series')
                        ->icon('heroicon-s-trash')
                        ->color('danger')
                        ->action(function ($record) {
                            $record->series()->delete();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-s-trash')
                        ->modalIcon('heroicon-s-trash')
                        ->modalDescription('This action will permanently delete all series associated with the playlist. Proceed with caution.')
                        ->modalSubmitActionLabel('Purge now')
                        ->hidden(fn($record): bool => !$record->xtream),
                    Tables\Actions\Action::make('duplicate_pair')
                        ->label('Duplicate & pair')
                        ->icon('heroicon-o-document-duplicate')
                        ->action(fn($record) => $record->duplicateWithPair())
                        ->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Playlist duplicated')
                                ->body('A paired duplicate has been created.')
                                ->duration(3000)
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->modalIcon('heroicon-o-document-duplicate')
                        ->modalDescription('Create a copy of this playlist and pair it so changes stay in sync.')
                        ->modalSubmitActionLabel('Duplicate'),
                    Tables\Actions\Action::make('unpair')
                        ->label('Unpair')
                        ->icon('heroicon-o-link-slash')
                        ->visible(fn($record): bool => (bool) $record->paired_playlist_id)
                        ->action(fn($record) => $record->unpair())
                        ->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Playlists unpaired')
                                ->body('This playlist is no longer paired.')
                                ->duration(3000)
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->modalIcon('heroicon-o-link-slash')
                        ->modalDescription('Unpair this playlist from its partner?')
                        ->modalSubmitActionLabel('Unpair'),
                    Tables\Actions\DeleteAction::make(),
                ])->button()->hiddenLabel()->size('sm'),
                Tables\Actions\EditAction::make()->button()->hiddenLabel()->size('sm'),
                Tables\Actions\ViewAction::make()
                    ->button()->hiddenLabel()->size('sm'),
            ], position: Tables\Enums\ActionsPosition::BeforeCells)
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('process')
                        ->label('Process selected')
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                $record->update([
                                    'status' => Status::Processing,
                                    'progress' => 0,
                                ]);
                                app('Illuminate\Contracts\Bus\Dispatcher')
                                    ->dispatch(new \App\Jobs\ProcessM3uImport($record, force: true));
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Selected playlists are processing')
                                ->body('The selected playlists are being processed in the background. Depending on the size of your playlist, this may take a while.')
                                ->duration(10000)
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-path')
                        ->modalIcon('heroicon-o-arrow-path')
                        ->modalDescription('Process the selected playlist(s) now?')
                        ->modalSubmitActionLabel('Yes, process now'),
                    Tables\Actions\BulkAction::make('reset')
                        ->label('Reset status')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->color('warning')
                        ->action(function ($records) {
                            foreach ($records as $record) {
                                $record->update([
                                    'status' => Status::Pending,
                                    'processing' => false,
                                    'progress' => 0,
                                    'series_progress' => 0,
                                    'channels' => 0,
                                    'synced' => null,
                                    'errors' => null,
                                ]);
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Playlist status reset')
                                ->body('Status has been reset for the selected Playlists.')
                                ->duration(3000)
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->modalIcon('heroicon-o-arrow-uturn-left')
                        ->modalDescription('Reset status for the selected Playlists so they can be processed again. Only perform this action if you are having problems with the playlist syncing.')
                        ->modalSubmitActionLabel('Yes, reset now'),
                    Tables\Actions\BulkAction::make('reset_active_count')
                        ->label('Reset active count')
                        ->icon('heroicon-o-numbered-list')
                        ->color('warning')
                        ->action(function ($records) {
                            foreach ($records as $record) {
                                Redis::set("active_streams:{$record->id}", 0);
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Active stream count reset')
                                ->body('Active stream count has been reset for the selected Playlists.')
                                ->duration(3000)
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->modalIcon('heroicon-o-numbered-list')
                        ->modalDescription('Reset active streams count for the selected Playlists. Proceed with caution as this could lead to an incorrect count if there are streams currently running.')
                        ->modalSubmitActionLabel('Yes, reset now'),
                    Tables\Actions\BulkAction::make('pair_playlists')
                        ->label('Pair playlists')
                        ->icon('heroicon-o-link')
                        ->action(function (Collection $records) {
                            if ($records->count() !== 2) {
                                Notification::make()
                                    ->danger()
                                    ->title('Please select exactly two playlists to pair.')
                                    ->duration(3000)
                                    ->send();
                                return;
                            }
                            [$first, $second] = [$records->first(), $records->last()];
                            if (!$first->pairWith($second)) {
                                Notification::make()
                                    ->danger()
                                    ->title('Playlists must be identical before pairing.')
                                    ->duration(3000)
                                    ->send();
                                return;
                            }
                            Notification::make()
                                ->success()
                                ->title('Playlists paired')
                                ->body('The selected playlists will now stay in sync.')
                                ->duration(3000)
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->modalIcon('heroicon-o-link')
                        ->modalDescription('Pair the selected playlists so that changes made to one are mirrored to the other.')
                        ->modalSubmitActionLabel('Pair'),
                    Tables\Actions\BulkAction::make('unpair_playlists')
                        ->label('Unpair playlists')
                        ->icon('heroicon-o-link-slash')
                        ->action(function (Collection $records) {
                            foreach ($records as $record) {
                                $record->unpair();
                            }
                            Notification::make()
                                ->success()
                                ->title('Playlists unpaired')
                                ->body('Selected playlists have been unpaired.')
                                ->duration(3000)
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->modalIcon('heroicon-o-link-slash')
                        ->modalDescription('Remove pairing so selected playlists no longer stay in sync.')
                        ->modalSubmitActionLabel('Unpair'),
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])->checkIfRecordIsSelectableUsing(
                fn($record): bool => $record->status !== Status::Processing,
            );
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
            // Playlists
            'index' => Pages\ListPlaylists::route('/'),
            'create' => Pages\CreatePlaylist::route('/create'),
            'view' => Pages\ViewPlaylist::route('/{record}'),
            'edit' => Pages\EditPlaylist::route('/{record}/edit'),

            // Playlist Sync Statuses
            'playlist-sync-statuses.index' => ListPlaylistSyncStatuses::route('/{parent}/syncs'),
            //'playlist-sync-statuses.create' => CreatePlaylistSyncStatus::route('/{parent}/syncs/create'),
            'playlist-sync-statuses.view' => ViewPlaylistSyncStatus::route('/{parent}/syncs/{record}'),
            //'playlist-sync-statuses.edit' => EditPlaylistSyncStatus::route('/{parent}/syncs/{record}/edit'),
        ];
    }

    public static function getHeaderActions(): array
    {
        return [
            Actions\Action::make('Sync Logs')
                ->label('Sync Logs')
                ->color('gray')
                ->icon('heroicon-m-arrows-right-left')
                ->url(
                    fn(Playlist $record): string => PlaylistResource::getUrl(
                        name: 'playlist-sync-statuses.index',
                        parameters: [
                            'parent' => $record->id,
                        ]
                    )
                ),
            Actions\ActionGroup::make([
                Actions\Action::make('process')
                    ->label('Sync and Process')
                    ->icon('heroicon-o-arrow-path')
                    ->action(function ($record) {
                        $record->update([
                            'status' => Status::Processing,
                            'progress' => 0,
                        ]);
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new \App\Jobs\ProcessM3uImport($record, force: true));
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Playlist is processing')
                            ->body('Playlist is being processed in the background. Depending on the size of your playlist, this may take a while. You will be notified on completion.')
                            ->duration(10000)
                            ->send();
                    })
                    ->disabled(fn($record): bool => $record->status === Status::Processing)
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrow-path')
                    ->modalIcon('heroicon-o-arrow-path')
                    ->modalDescription('Process playlist now?')
                    ->modalSubmitActionLabel('Yes, process now'),
                Actions\Action::make('process_series')
                    ->label('Fetch Series Metadata')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(function ($record) {
                        $record->update([
                            'status' => Status::Processing,
                            'series_progress' => 0,
                        ]);
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new \App\Jobs\ProcessM3uImportSeries($record, force: true));
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Playlist is fetching metadata for Series')
                            ->body('Playlist Series are being processed in the background. Depending on the number of enabled Series, this may take a while. You will be notified on completion.')
                            ->duration(10000)
                            ->send();
                    })
                    ->disabled(fn($record): bool => $record->status === Status::Processing)
                    ->hidden(fn($record): bool => !$record->xtream)
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrow-down-tray')
                    ->modalIcon('heroicon-o-arrow-down-tray')
                    ->modalDescription('Fetch Series metadata for this playlist now? Only enabled Series will be included.')
                    ->modalSubmitActionLabel('Yes, process now'),
                Actions\Action::make('process_vod')
                    ->label('Fetch VOD Metadata')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(function ($record) {
                        $record->update([
                            'status' => Status::Processing,
                            'progress' => 0,
                        ]);
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new \App\Jobs\ProcessVodChannels(playlist: $record));
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Playlist is fetching metadata for VOD channels')
                            ->body('Playlist VOD channels are being processed in the background. Depending on the number of enabled VOD channels, this may take a while. You will be notified on completion.')
                            ->duration(10000)
                            ->send();
                    })
                    ->disabled(fn($record): bool => $record->status === Status::Processing)
                    ->hidden(fn($record): bool => !$record->xtream)
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrow-down-tray')
                    ->modalIcon('heroicon-o-arrow-down-tray')
                    ->modalDescription('Fetch VOD metadata for this playlist now? Only enabled VOD channels will be included.')
                    ->modalSubmitActionLabel('Yes, process now'),
                Actions\Action::make('Download M3U')
                    ->label('Download M3U')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn($record) => \App\Facades\PlaylistUrlFacade::getUrls($record)['m3u'])
                    ->openUrlInNewTab(),
                EpgCacheService::getEpgPlaylistAction(),
                Actions\Action::make('HDHomeRun URL')
                    ->label('HDHomeRun URL')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn($record) => \App\Facades\PlaylistUrlFacade::getUrls($record)['hdhr'])
                    ->openUrlInNewTab(),
                Actions\Action::make('Duplicate')
                    ->label('Duplicate')
                    ->form([
                        Forms\Components\TextInput::make('name')
                            ->label('Playlist name')
                            ->required()
                            ->helperText('This will be the name of the duplicated playlist.'),
                    ])
                    ->action(function ($record, $data) {
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new \App\Jobs\DuplicatePlaylist($record, $data['name']));
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Playlist is being duplicated')
                            ->body('Playlist is being duplicated in the background. You will be notified on completion.')
                            ->duration(3000)
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-document-duplicate')
                    ->modalIcon('heroicon-o-document-duplicate')
                    ->modalDescription('Duplicate playlist now?')
                    ->modalSubmitActionLabel('Yes, duplicate now'),
                Actions\Action::make('reset')
                    ->label('Reset status')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->action(function ($record) {
                        $record->update([
                            'status' => Status::Pending,
                            'processing' => false,
                            'progress' => 0,
                            'series_progress' => 0,
                            'channels' => 0,
                            'synced' => null,
                            'errors' => null,
                        ]);
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Playlist status reset')
                            ->body('Playlist status has been reset.')
                            ->duration(3000)
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->modalIcon('heroicon-o-arrow-uturn-left')
                    ->modalDescription('Reset playlist status so it can be processed again. Only perform this action if you are having problems with the playlist syncing.')
                    ->modalSubmitActionLabel('Yes, reset now'),
                Actions\Action::make('reset_active_count')
                    ->label('Reset active count')
                    ->icon('heroicon-o-numbered-list')
                    ->color('warning')
                    ->action(fn($record) => Redis::set("active_streams:{$record->id}", 0))->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Active stream count reset')
                            ->body('Playlist active stream count has been reset.')
                            ->duration(3000)
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->modalIcon('heroicon-o-numbered-list')
                    ->modalDescription('Reset playlist active streams count. Proceed with caution as this could lead to an incorrect count if there are streams currently running.')
                    ->modalSubmitActionLabel('Yes, reset now'),
                Actions\DeleteAction::make(),
            ])->button(),
        ];
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        $links = [
            Infolists\Components\Livewire::make(PlaylistM3uUrl::class),
            Infolists\Components\Livewire::make(PlaylistEpgUrl::class),
        ];
        if (PlaylistUrlFacade::mediaFlowProxyEnabled()) {
            $links[] = Infolists\Components\Livewire::make(MediaFlowProxyUrl::class);
        };
        return $infolist
            ->schema([
                Infolists\Components\Tabs::make()
                    ->persistTabInQueryString()
                    ->columnSpanFull()
                    ->tabs([
                        Infolists\Components\Tabs\Tab::make('Details')
                            ->icon('heroicon-o-play')
                            ->schema([
                                Infolists\Components\Livewire::make(PlaylistInfo::class),
                            ]),
                        Infolists\Components\Tabs\Tab::make('Links')
                            ->icon('heroicon-m-link')
                            ->schema([
                                Infolists\Components\Section::make()
                                    ->columns(2)
                                    ->schema($links)
                            ]),
                        Infolists\Components\Tabs\Tab::make('Xtream API')
                            ->icon('heroicon-m-bolt')
                            ->schema([
                                Infolists\Components\Section::make()
                                    ->columns(1)
                                    ->schema([
                                        Infolists\Components\Livewire::make(XtreamApiInfo::class)
                                    ])
                            ]),
                    ])->contained(false),
                Infolists\Components\Livewire::make(EpgViewer::class)
                    ->columnSpanFull(),
            ]);
    }

    public static function getFormSections($creating = false): array
    {
        // Define the form fields for each section
        $nameFields = [
            Forms\Components\TextInput::make('name')
                ->helperText('Enter the name of the playlist. Internal use only.')
                ->required(),
            Forms\Components\Select::make('parent_playlist_id')
                ->label('Parent Playlist')
                ->options(function ($record) {
                    return \App\Models\Playlist::where('user_id', \Illuminate\Support\Facades\Auth::id())
                        ->when($record, fn($q) => $q->where('id', '!=', $record->id))
                        ->pluck('name', 'id');
                })
                ->searchable()
                ->placeholder('None')
                ->helperText('Choose a parent playlist to sync content from.'),
            Forms\Components\Grid::make()
                ->columnSpanFull()
                ->columns(3)
                ->schema([
                    Forms\Components\Toggle::make('short_urls_enabled')
                        ->label('Use Short URLs')
                        ->helperText('When enabled, short URLs will be used for the playlist links. Save changes to generate the short URLs (or remove them).')
                        ->columnSpan(2)
                        ->inline(false)
                        ->default(false),
                    Forms\Components\Toggle::make('edit_uuid')
                        ->label('View/Update Unique Identifier')
                        ->columnSpanFull()
                        ->inline(false)
                        ->live()
                        ->dehydrated(false)
                        ->default(false),
                    Forms\Components\TextInput::make('uuid')
                        ->label('Unique Identifier')
                        ->columnSpanFull()
                        ->rules(function ($record) {
                            return [
                                'required',
                                'min:3',
                                'max:36',
                                \Illuminate\Validation\Rule::unique('playlists', 'uuid')->ignore($record?->id),
                            ];
                        })
                        ->helperText('Value must be between 3 and 36 characters.')
                        ->hintIcon(
                            'heroicon-m-exclamation-triangle',
                            tooltip: 'Be careful changing this value as this will change the URLs for the Playlist, its EPG, and HDHR.'
                        )
                        ->hidden(fn($get): bool => !$get('edit_uuid'))
                        ->required(),
                ])->hiddenOn('create'),
        ];

        $typeFields = [
            Forms\Components\Grid::make()
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Forms\Components\ToggleButtons::make('xtream')
                        ->label('Playlist type')
                        ->grouped()
                        ->options([
                            false => 'm3u8 url or local file',
                            true => 'Xtream API',
                        ])
                        ->icons([
                            false => 'heroicon-s-link',
                            true => 'heroicon-s-bolt',
                        ])
                        ->default(false)
                        ->live(),
                    Forms\Components\TextInput::make('xtream_config.url')
                        ->label('Xtream API URL')
                        ->live()
                        ->helperText('Enter the full url, using <url>:<port> format - without trailing slash (/).')
                        ->prefixIcon('heroicon-m-globe-alt')
                        ->maxLength(255)
                        ->url()
                        ->columnSpan(2)
                        ->required()
                        ->hidden(fn(Get $get): bool => !$get('xtream')),
                    Forms\Components\Grid::make()
                        ->columnSpanFull()
                        ->schema([
                            Forms\Components\Fieldset::make('Config')
                                ->columns(3)
                                ->schema([
                                    Forms\Components\TextInput::make('xtream_config.username')
                                        ->label('Xtream API Username')
                                        ->live()
                                        ->required()
                                        ->columnSpan(1),
                                    Forms\Components\TextInput::make('xtream_config.password')
                                        ->label('Xtream API Password')
                                        ->live()
                                        ->required()
                                        ->columnSpan(1)
                                        ->password()
                                        ->revealable(),
                                    Forms\Components\Select::make('xtream_config.output')
                                        ->label('Output')
                                        ->required()
                                        ->columnSpan(1)
                                        ->options([
                                            'ts' => 'MPEG-TS (.ts)',
                                            'm3u8' => 'HLS (.m3u8)',
                                        ])->default('ts'),
                                    Forms\Components\CheckboxList::make('xtream_config.import_options')
                                        ->label('Groups and Streams to Import')
                                        ->columnSpan(2)
                                        ->live()
                                        ->options([
                                            'live' => 'Live',
                                            'vod' => 'VOD',
                                            'series' => 'Series',
                                        ])->helperText('NOTE: Playlist series can be managed in the Series section. You will need to enabled the VOD channels and Series you wish to import metadata for as it will only be imported for enabled channels and series.'),
                                    Forms\Components\Toggle::make('xtream_config.import_epg')
                                        ->label('Import EPG')
                                        ->helperText('If your provider supports EPG, you can import it automatically.')
                                        ->columnSpan(1)
                                        ->inline(false)
                                        ->default(true),
                                ]),
                        ])->hidden(fn(Get $get): bool => !$get('xtream')),
                    Forms\Components\TextInput::make('url')
                        ->label('URL or Local file path')
                        ->columnSpan(2)
                        ->prefixIcon('heroicon-m-globe-alt')
                        ->helperText('Enter the URL of the playlist file. If this is a local file, you can enter a full or relative path. If changing URL, the playlist will be re-imported. Use with caution as this could lead to data loss if the new playlist differs from the old one.')
                        ->requiredWithout('uploads')
                        ->rules([new CheckIfUrlOrLocalPath()])
                        ->maxLength(255)
                        ->hidden(fn(Get $get): bool => !!$get('xtream')),
                    Forms\Components\FileUpload::make('uploads')
                        ->label('File')
                        ->columnSpan(2)
                        ->disk('local')
                        ->directory('playlist')
                        ->helperText('Upload the playlist file. This will be used to import groups and channels.')
                        ->rules(['file'])
                        ->requiredWithout('url')
                        ->hidden(fn(Get $get): bool => !!$get('xtream')),
                ]),

            Forms\Components\Grid::make()
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    Forms\Components\TextInput::make('user_agent')
                        ->helperText('User agent string to use for fetching the playlist.')
                        ->default('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36')
                        ->columnSpan(2)
                        ->required(),
                    Forms\Components\Toggle::make('disable_ssl_verification')
                        ->label('Disable SSL verification')
                        ->helperText('Only disable this if you are having issues.')
                        ->columnSpan(1)
                        ->onColor('danger')
                        ->inline(false)
                        ->default(false),
                ])
        ];

        $schedulingFields = [
            Forms\Components\Grid::make()
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Forms\Components\Grid::make()
                        ->columns(3)
                        ->columnSpanFull()
                        ->schema([
                            Forms\Components\Toggle::make('auto_sync')
                                ->label('Automatically sync playlist')
                                ->helperText('When enabled, the playlist will be automatically re-synced at the specified interval.')
                                ->live()
                                ->columnSpan(2)
                                ->inline(false)
                                ->default(true),
                            Forms\Components\Select::make('sync_interval')
                                ->label('Sync Every')
                                ->helperText('Default is every 24hr if left empty.')
                                ->columnSpan(1)
                                ->options([
                                    '15 minutes' => '15 minutes',
                                    '30 minutes' => '30 minutes',
                                    '45 minutes' => '45 minutes',
                                    '1 hour' => '1 hour',
                                    '2 hours' => '2 hours',
                                    '3 hours' => '3 hours',
                                    '4 hours' => '4 hours',
                                    '5 hours' => '5 hours',
                                    '6 hours' => '6 hours',
                                    '7 hours' => '7 hours',
                                    '8 hours' => '8 hours',
                                    '12 hours' => '12 hours',
                                    '24 hours' => '24 hours',
                                    '2 days' => '2 days',
                                    '3 days' => '3 days',
                                    '1 week' => '1 week',
                                    '2 weeks' => '2 weeks',
                                    '1 month' => '1 month',
                                ])->hidden(fn(Get $get): bool => !$get('auto_sync')),
                            Forms\Components\Toggle::make('backup_before_sync')
                                ->label('Backup Before Sync')
                                ->helperText('When enabled, a backup will be created before syncing.')
                                ->columnSpanFull()
                                ->inline(false)
                                ->default(false),
                        ]),

                    Forms\Components\DateTimePicker::make('synced')
                        ->columnSpan(2)
                        ->suffix(config('app.timezone'))
                        ->native(false)
                        ->label('Last Synced')
                        ->hidden(fn(Get $get, string $operation): bool => !$get('auto_sync') || $operation === 'create')
                        ->helperText('Playlist will be synced at the specified interval. Timestamp is automatically updated after each sync. Set to any time in the past (or future) and the next sync will run when the defined interval has passed since the time set.'),
                ])
        ];

        $processingFields = [
            Forms\Components\Grid::make()
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Forms\Components\Toggle::make('import_prefs.preprocess')
                        ->label('Preprocess playlist')
                        ->columnSpan(1)
                        ->live()
                        ->inline(true)
                        ->default(false)
                        ->helperText('When enabled, the playlist will be preprocessed before importing. You can then select which groups you would like to import.'),
                    Forms\Components\Toggle::make('enable_channels')
                        ->label('Enable new channels')
                        ->columnSpan(1)
                        ->inline(true)
                        ->default(false)
                        ->helperText('When enabled, newly added channels will be enabled by default.'),
                    Forms\Components\Toggle::make('import_prefs.use_regex')
                        ->label('Use regex for filtering')
                        ->columnSpan(2)
                        ->inline(true)
                        ->live()
                        ->default(false)
                        ->helperText('When enabled, groups will be included based on regex pattern match instead of prefix.')
                        ->hidden(fn(Get $get): bool => !$get('import_prefs.preprocess') || !$get('status')),
                    Forms\Components\Select::make('import_prefs.selected_groups')
                        ->label('Groups to import')
                        ->columnSpan(1)
                        ->searchable()
                        ->multiple()
                        ->helperText('NOTE: If the list is empty, sync the playlist and check again once complete.')
                        ->options(
                            fn($record): array =>
                            SourceGroup::where('playlist_id', $record->id)
                                ->get()->pluck('name', 'name')->toArray()
                        )
                        ->hidden(fn(Get $get): bool => !$get('import_prefs.preprocess') || !$get('status')),
                    Forms\Components\TagsInput::make('import_prefs.included_group_prefixes')
                        ->label(fn(Get $get) => !$get('import_prefs.use_regex') ? 'Group prefixes to import' : 'Regex patterns to import')
                        ->helperText('Press [tab] or [return] to add item.')
                        ->columnSpan(1)
                        ->suggestions([
                            'US -',
                            'UK -',
                            'CA -',
                            '^(US|UK|CA)',
                            'Sports.*HD$',
                            '\[.*\]'
                        ])
                        ->splitKeys(['Tab', 'Return'])
                        ->hidden(fn(Get $get): bool => !$get('import_prefs.preprocess') || !$get('status')),
                    Forms\Components\TagsInput::make('import_prefs.ignored_file_types')
                        ->label('Ignored file types')
                        ->helperText('Press [tab] or [return] to add item. You can ignore certain file types from being imported (.e.g.: ".mkv", ".mp4", etc.) This is useful for ignoring VOD or other unwanted content.')
                        ->columnSpan(2)
                        ->suggestions([
                            '.avi',
                            '.mkv',
                            '.mp4',
                        ])->splitKeys(['Tab', 'Return']),
                ]),
        ];

        $outputFields = [
            Forms\Components\Section::make('Playlist Output')
                ->description('Determines how the playlist is output')
                ->columnSpanFull()
                ->collapsible()
                ->collapsed($creating)
                ->columns(2)
                ->schema([
                    Forms\Components\Toggle::make('sync_logs_enabled')
                        ->label('Enable Sync Logs')
                        ->columnSpan('full')
                        ->inline(false)
                        ->live()
                        ->default(true)
                        ->disabled(fn(Get $get): bool => config('dev.disable_sync_logs', false))
                        ->hint(fn(Get $get): string => config('dev.disable_sync_logs', false) ? 'Sync logs disabled globally in settings' : '')
                        ->hintIcon(fn(Get $get): string => config('dev.disable_sync_logs', false) ? 'heroicon-m-lock-closed' : '')
                        ->helperText('Retain logs of playlist syncs. This is useful for debugging and tracking changes to the playlist. This can lead to increased sync time and storage usage depending on the size of the playlist.'),
                    Forms\Components\Toggle::make('auto_sort')
                        ->label('Automatically assign sort number based on playlist order')
                        ->columnSpan(1)
                        ->inline(false)
                        ->default(true)
                        ->helperText('NOTE: You will need to re-sync your playlist, or wait for the next scheduled sync, if changing this. This will overwrite any existing channel sort order customization for this playlist.'),
                    Forms\Components\Toggle::make('auto_channel_increment')
                        ->label('Auto channel number increment')
                        ->columnSpan(1)
                        ->inline(false)
                        ->live()
                        ->default(false)
                        ->helperText('If no channel number is set, output an automatically incrementing number.'),
                    Forms\Components\TextInput::make('channel_start')
                        ->helperText('The starting channel number.')
                        ->columnSpan(1)
                        ->rules(['min:1'])
                        ->type('number')
                        ->hidden(fn(Get $get): bool => !$get('auto_channel_increment'))
                        ->required(),
                ]),
            Forms\Components\Section::make('Streaming Output')
                ->description('Output processing options')
                ->columnSpanFull()
                ->collapsible()
                ->collapsed($creating)
                ->columns(2)
                ->schema([
                    Forms\Components\Toggle::make('enable_proxy')
                        ->label('Enable Proxy')
                        ->hint(fn(Get $get): string => $get('enable_proxy') ? 'Proxied' : 'Not proxied')
                        ->hintIcon(fn(Get $get): string => !$get('enable_proxy') ? 'heroicon-m-lock-open' : 'heroicon-m-lock-closed')
                        ->live()
                        ->helperText('When enabled, all streams will be proxied through the application. This allows for better compatibility with various clients and enables features such as stream limiting and output format selection.')
                        ->inline(false)
                        ->default(false),
                    Forms\Components\TextInput::make('streams')
                        ->label('HDHR/Xtream API Streams')
                        ->helperText('Number of streams available for HDHR and Xtream API service (if using).')
                        ->columnSpan(1)
                        ->hintIcon(
                            'heroicon-m-question-mark-circle',
                            tooltip: 'Enter 0 to use to use provider defined value. This value is also used when generating the Xtream API user info response.'
                        )
                        ->rules(['min:0'])
                        ->type('number')
                        ->default(0) // Default to 0 streams (unlimited)
                        ->required(),
                    Forms\Components\TextInput::make('available_streams')
                        ->label('Available Streams')
                        ->hint('Set to 0 for unlimited streams.')
                        ->helperText('Number of streams available for this provider. If set to a value other than 0, will prevent any streams from starting if the number of active streams exceeds this value.')
                        ->columnSpan(1)
                        ->rules(['min:1'])
                        ->type('number')
                        ->default(0) // Default to 0 streams (for unlimted)
                        ->required()
                        ->hidden(fn(Get $get): bool => !$get('enable_proxy')),
                    Forms\Components\Select::make('proxy_options.output')
                        ->label('Proxy Output Format')
                        ->required()
                        ->options([
                            'ts' => 'MPEG-TS (.ts)',
                            'hls' => 'HLS (.m3u8)',
                        ])
                        ->default('ts')
                        ->helperText(fn() => config('proxy.shared_streaming.enabled') ? '' : 'NOTE: Only HLS streaming supports multiple connections per stream. MPEG-TS creates a new stream for each connection.')
                        ->hidden(fn(Get $get): bool => !$get('enable_proxy')),
                    Forms\Components\TextInput::make('server_timezone')
                        ->label('Provider Timezone')
                        ->helperText('The portal/provider timezone (DST-aware). Needed to correctly use timeshift functionality when playlist proxy is enabled.')
                        ->placeholder('Etc/UTC')
                        ->hidden(fn(Get $get): bool => !$get('enable_proxy')),
                ]),
            Forms\Components\Section::make('EPG Output')
                ->description('EPG output options')
                ->columnSpanFull()
                ->collapsible()
                ->collapsed($creating)
                ->columns(2)
                ->schema([
                    Forms\Components\Toggle::make('dummy_epg')
                        ->label('Enable dummy EPG')
                        ->columnSpan(1)
                        ->live()
                        ->inline(false)
                        ->default(false)
                        ->helperText('When enabled, dummy EPG data will be generated for the next 5 days. Thus, it is possible to assign channels for which no EPG data is available. As program information, the channel title and the set program length are used.'),
                    Forms\Components\Select::make('id_channel_by')
                        ->label('Preferred TVG ID output')
                        ->helperText('How you would like to ID your channels in the EPG.')
                        ->options([
                            'stream_id' => 'TVG ID/Stream ID (default)',
                            'channel_id' => 'Channel Number (recommended for HDHR)',
                            'name' => 'Channel Name',
                            'title' => 'Channel Title',
                        ])
                        ->required()
                        ->default('stream_id') // Default to stream_id
                        ->columnSpan(1),
                    Forms\Components\Toggle::make('dummy_epg_category')
                        ->label('Channel group as category')
                        ->columnSpan(1)
                        ->inline(false)
                        ->default(false)
                        ->helperText('When enabled, the channel group will be assigned to the dummy EPG as a <category> tag.')
                        ->hidden(fn(Get $get): bool => !$get('dummy_epg')),
                    Forms\Components\TextInput::make('dummy_epg_length')
                        ->label('Dummy program length (in minutes)')
                        ->columnSpan(1)
                        ->rules(['min:1'])
                        ->type('number')
                        ->default(120)
                        ->hidden(fn(Get $get): bool => !$get('dummy_epg')),
                ]),
        ];

        // Return sections and fields
        return [
            'Name' => $nameFields,
            'Type' => $typeFields,
            'Scheduling' => $schedulingFields,
            'Processing' => $processingFields,
            'Output' => $outputFields,
        ];
    }

    public static function getForm(): array
    {
        $tabs = [];
        foreach (self::getFormSections(creating: false) as $section => $fields) {
            if ($section === 'Name') {
                $section = 'General';
            }
            if ($section !== 'Output') {
                // Wrap the fields in a section
                $fields = [
                    Forms\Components\Section::make($section)
                        ->schema($fields),
                ];

                // If general section, add AUTH management
                if ($section === 'General') {
                    $fields[] = Forms\Components\Grid::make()
                        ->columns(2)
                        ->columnSpanFull()
                        ->schema([
                            Forms\Components\Section::make('Auth')
                                ->compact()
                                ->description('Add and manage authentication.')
                                ->columnSpanFull()
                                ->schema([
                                    Forms\Components\Select::make('assigned_auth_ids')
                                        ->label('Assigned Auths')
                                        ->multiple()
                                        ->options(function ($record) {
                                            $options = [];

                                            // Get currently assigned auths for this playlist
                                            if ($record) {
                                                $currentAuths = $record->playlistAuths()->get();
                                                foreach ($currentAuths as $auth) {
                                                    $options[$auth->id] = $auth->name . ' (currently assigned)';
                                                }
                                            }

                                            // Get unassigned auths
                                            $unassignedAuths = \App\Models\PlaylistAuth::where('user_id', \Illuminate\Support\Facades\Auth::id())
                                                ->whereDoesntHave('assignedPlaylist')
                                                ->get();

                                            foreach ($unassignedAuths as $auth) {
                                                $options[$auth->id] = $auth->name;
                                            }

                                            return $options;
                                        })
                                        ->searchable()
                                        ->nullable()
                                        ->placeholder('Select auths or leave empty')
                                        ->default(function ($record) {
                                            if ($record) {
                                                return $record->playlistAuths()->pluck('playlist_auths.id')->toArray();
                                            }
                                            return [];
                                        })
                                        ->afterStateHydrated(function ($component, $state, $record) {
                                            if ($record) {
                                                $currentAuthIds = $record->playlistAuths()->pluck('playlist_auths.id')->toArray();
                                                $component->state($currentAuthIds);
                                            }
                                        })
                                        ->hintIcon(
                                            'heroicon-m-question-mark-circle',
                                            tooltip: 'Only unassigned auths are available. Each auth can only be assigned to one playlist at a time. You will also be able to access the Xtream API using any assigned auths.'
                                        )
                                        ->helperText('Simple authentication for playlist access.')
                                        ->afterStateUpdated(function ($state, $record) {
                                            if (!$record) return;

                                            $currentAuthIds = $record->playlistAuths()->pluck('playlist_auths.id')->toArray();
                                            $newAuthIds = $state ? (is_array($state) ? $state : [$state]) : [];

                                            // Find auths to remove (currently assigned but not in new selection)
                                            $authsToRemove = array_diff($currentAuthIds, $newAuthIds);
                                            foreach ($authsToRemove as $authId) {
                                                $auth = \App\Models\PlaylistAuth::find($authId);
                                                if ($auth) {
                                                    $auth->clearAssignment();
                                                }
                                            }

                                            // Find auths to add (in new selection but not currently assigned)
                                            $authsToAdd = array_diff($newAuthIds, $currentAuthIds);
                                            foreach ($authsToAdd as $authId) {
                                                $auth = \App\Models\PlaylistAuth::find($authId);
                                                if ($auth) {
                                                    $auth->assignTo($record);
                                                }
                                            }
                                        })
                                        ->dehydrated(false), // Don't save this field directly
                                ]),
                        ]);
                };
            }

            $tabs[] = Forms\Components\Tabs\Tab::make($section)
                ->schema($fields);
        }

        // Compose the form with tabs and sections
        return [
            Forms\Components\Grid::make()
                ->columns(3)
                ->schema([
                    Forms\Components\Tabs::make()
                        ->tabs($tabs)
                        ->columnSpanFull()
                        ->contained(false)
                        ->persistTabInQueryString(),
                ])->columnSpanFull(),

        ];
    }

    public static function getFormSteps(): array
    {
        $wizard = [];
        foreach (self::getFormSections(creating: true) as $step => $fields) {
            $wizard[] = Forms\Components\Wizard\Step::make($step)
                ->schema($fields);

            // Add auth after type step
            if ($step === 'Type') {
                $wizard[] = Forms\Components\Wizard\Step::make('Auth')
                    ->schema([
                        Forms\Components\ToggleButtons::make('auth_option')
                            ->label('Authentication Option')
                            ->options([
                                'none' => 'No Authentication',
                                'existing' => 'Use Existing Auth',
                                'create' => 'Create New Auth',
                            ])
                            ->icons([
                                'none' => 'heroicon-o-lock-open',
                                'existing' => 'heroicon-o-key',
                                'create' => 'heroicon-o-plus',
                            ])
                            ->default('none')
                            ->live()
                            ->inline()
                            ->grouped()
                            ->columnSpanFull(),

                        // Existing Auth Selection
                        Forms\Components\Select::make('existing_auth_id')
                            ->label('Select Existing Auth')
                            ->helperText('Only unassigned auths are available. Each auth can only be assigned to one playlist at a time.')
                            ->options(function () {
                                return \App\Models\PlaylistAuth::where('user_id', \Illuminate\Support\Facades\Auth::id())
                                    ->whereDoesntHave('assignedPlaylist')
                                    ->pluck('name', 'id')
                                    ->toArray();
                            })
                            ->searchable()
                            ->placeholder('Select an auth to assign')
                            ->columnSpanFull()
                            ->visible(fn(Forms\Get $get): bool => $get('auth_option') === 'existing'),

                        // Create New Auth Fields
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('auth_name')
                                    ->label('Auth Name')
                                    ->helperText('Internal name for this authentication.')
                                    ->placeholder('Auth for My Playlist')
                                    ->required()
                                    ->columnSpan(2),

                                Forms\Components\TextInput::make('auth_username')
                                    ->label('Username')
                                    ->helperText('Username for playlist access.')
                                    ->required()
                                    ->columnSpan(1),

                                Forms\Components\TextInput::make('auth_password')
                                    ->label('Password')
                                    ->password()
                                    ->revealable()
                                    ->helperText('Password for playlist access.')
                                    ->required()
                                    ->columnSpan(1),
                            ])
                            ->visible(fn(Forms\Get $get): bool => $get('auth_option') === 'create'),
                    ]);
            }
        }
        return $wizard;
    }
}
