<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CategoryResource\Pages;
use App\Filament\Resources\CategoryResource\RelationManagers;
use App\Models\Category;
use App\Models\CustomPlaylist;
use App\Jobs\SyncPlaylistChildren;
use App\Filament\BulkActions\HandlesSourcePlaylist;
use App\Filament\Concerns\DisplaysPlaylistMembership;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class CategoryResource extends Resource
{
    use HandlesSourcePlaylist;
    use DisplaysPlaylistMembership;

    protected static ?string $model = Category::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'name_internal'];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()
            ->where('user_id', auth()->id())
            ->whereHas('playlist', fn (Builder $query) => $query->whereNull('parent_id'));
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereHas('playlist', fn (Builder $query) => $query->whereNull('parent_id'));
    }

    protected static ?string $navigationGroup = 'Series';

    public static function getNavigationSort(): ?int
    {
        return 4;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table->persistFiltersInSession()
            ->persistSortInSession()
            ->modifyQueryUsing(function (Builder $query) {
                $query->withCount('series')
                    ->withCount('enabled_series');
            })
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label('Filters');
            })
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->columns([
                Tables\Columns\TextInputColumn::make('name')
                    ->label('Name')
                    ->rules(['min:0', 'max:255'])
                    ->placeholder(fn($record) => $record->name_internal)
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('name_internal')
                    ->label('Default name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('series_count')
                    ->label('Series')
                    ->description(fn(Category $record): string => "Enabled: {$record->enabled_series_count}")
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('playlist.name')
                    ->label('Playlist')
                    ->formatStateUsing(fn($state, Category $record) => self::playlistDisplay($record, 'source_category_id'))
                    ->tooltip(fn(Category $record) => self::playlistTooltip($record, 'source_category_id'))
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
                    ->relationship('playlist', 'name', fn (Builder $query) => $query->whereNull('parent_id'))
                    ->multiple()
                    ->preload()
                    ->searchable(),
            ])
            ->actions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\Action::make('add')
                        ->label('Add to Custom Playlist')
                        ->form([
                            Forms\Components\Select::make('playlist')
                                ->required()
                                ->live()
                                ->label('Custom Playlist')
                                ->helperText('Select the custom playlist you would like to add the selected series to.')
                                ->options(CustomPlaylist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                                ->afterStateUpdated(function (Forms\Set $set, $state) {
                                    if ($state) {
                                        $set('category', null);
                                    }
                                })
                                ->searchable(),
                            Forms\Components\Select::make('category')
                                ->label('Custom Category')
                                ->disabled(fn(Get $get) => !$get('playlist'))
                                ->helperText(fn(Get $get) => !$get('playlist') ? 'Select a custom playlist first.' : 'Select the category you would like to assign to the selected series to.')
                                ->options(function ($get) {
                                    $customList = CustomPlaylist::find($get('playlist'));
                                    return $customList ? $customList->tags()
                                        ->where('type', $customList->uuid . '-category')
                                        ->get()
                                        ->mapWithKeys(fn($tag) => [$tag->getAttributeValue('name') => $tag->getAttributeValue('name')])
                                        ->toArray() : [];
                                })
                                ->searchable(),
                        ])
                        ->action(function ($record, array $data): void {
                            $playlist = CustomPlaylist::findOrFail($data['playlist']);
                            $playlist->series()->syncWithoutDetaching($record->series()->pluck('id'));
                            if ($data['category']) {
                                $playlist->syncTagsWithType([$data['category']], $playlist->uuid);
                            }
                        })->after(function () {
                            FilamentNotification::make()
                                ->success()
                                ->title('Series added to custom playlist')
                                ->body('The selected series have been added to the chosen custom playlist.')
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-play')
                        ->modalIcon('heroicon-o-play')
                        ->modalDescription('Add the selected series to the chosen custom playlist.')
                        ->modalSubmitActionLabel('Add now'),
                    Tables\Actions\Action::make('move')
                        ->label('Move Series to Category')
                        ->form([
                            Forms\Components\Select::make('category')
                                ->required()
                                ->live()
                                ->label('Category')
                                ->helperText('Select the category you would like to move the series to.')
                                ->options(fn(Get $get, $record) => Category::where(['user_id' => auth()->id(), 'playlist_id' => $record->playlist_id])->get(['name', 'id'])->pluck('name', 'id'))
                                ->searchable(),
                        ])
                        ->action(function ($record, array $data): void {
                            $category = Category::findOrFail($data['category']);
                            $record->series()->update([
                                'category_id' => $category->id,
                            ]);
                            SyncPlaylistChildren::debounce($record->playlist, []);
                        })->after(function () {
                            FilamentNotification::make()
                                ->success()
                                ->title('Series moved to category')
                                ->body('The series have been moved to the chosen category.')
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrows-right-left')
                        ->modalIcon('heroicon-o-arrows-right-left')
                        ->modalDescription('Move the series to another category.')
                        ->modalSubmitActionLabel('Move now'),
                    Tables\Actions\Action::make('process')
                        ->label('Fetch Series Metadata')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->action(function ($record) {
                            foreach ($record->enabled_series as $series) {
                                app('Illuminate\Contracts\Bus\Dispatcher')
                                    ->dispatch(new \App\Jobs\ProcessM3uImportSeriesEpisodes(
                                        playlistSeries: $series,
                                    ));
                            }
                        })->after(function () {
                            FilamentNotification::make()
                                ->success()
                                ->title('Series are being processed')
                                ->body('You will be notified once complete.')
                                ->duration(10000)
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-down-tray')
                        ->modalIcon('heroicon-o-arrow-down-tray')
                        ->modalDescription('Process series for selected category now? Only enabled series will be processed. This will fetch all episodes and seasons for the category series. This may take a while depending on the number of series in the category.')
                        ->modalSubmitActionLabel('Yes, process now'),
                    Tables\Actions\Action::make('sync')
                        ->label('Sync Series .strm files')
                        ->action(function ($record) {
                            foreach ($record->enabled_series as $series) {
                                app('Illuminate\Contracts\Bus\Dispatcher')
                                    ->dispatch(new \App\Jobs\SyncSeriesStrmFiles(
                                        series: $series,
                                    ));
                            }
                        })->after(function () {
                            FilamentNotification::make()
                                ->success()
                                ->title('.strm files are being synced for selected category series. Only enabled series will be synced.')
                                ->body('You will be notified once complete.')
                                ->duration(10000)
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-document-arrow-down')
                        ->modalIcon('heroicon-o-document-arrow-down')
                        ->modalDescription('Sync selected category series .strm files now? This will generate .strm files for the enabled series at the path set for the series.')
                        ->modalSubmitActionLabel('Yes, sync now'),
                    Tables\Actions\Action::make('enable')
                        ->label('Enable selected')
                        ->action(function ($record): void {
                            $record->series()->update(['enabled' => true]);
                            SyncPlaylistChildren::debounce($record->playlist, []);
                        })->after(function () {
                            FilamentNotification::make()
                                ->success()
                                ->title('Selected category series enabled')
                                ->body('The selected category series have been enabled.')
                                ->send();
                        })
                        ->color('success')
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-check-circle')
                        ->modalIcon('heroicon-o-check-circle')
                        ->modalDescription('Enable the selected category series now?')
                        ->modalSubmitActionLabel('Yes, enable now'),
                    Tables\Actions\Action::make('disable')
                        ->label('Disable selected')
                        ->action(function ($record): void {
                            $record->series()->update(['enabled' => false]);
                            SyncPlaylistChildren::debounce($record->playlist, []);
                        })->after(function () {
                            FilamentNotification::make()
                                ->success()
                                ->title('Selected category series disabled')
                                ->body('The selected category series have been disabled.')
                                ->send();
                        })
                        ->color('warning')
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-x-circle')
                        ->modalIcon('heroicon-o-x-circle')
                        ->modalDescription('Disable the selected category series now?')
                        ->modalSubmitActionLabel('Yes, disable now'),
                ])->color('primary')->button()->hiddenLabel()->size('sm'),
            ], position: Tables\Enums\ActionsPosition::BeforeCells)
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('add')
                        ->label('Add to Custom Playlist')
                        ->form([
                            Forms\Components\Select::make('playlist')
                                ->required()
                                ->live()
                                ->label('Custom Playlist')
                                ->helperText('Select the custom playlist you would like to add the selected category series to.')
                                ->options(CustomPlaylist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                                ->afterStateUpdated(function (Forms\Set $set, $state) {
                                    if ($state) {
                                        $set('category', null);
                                    }
                                })
                                ->searchable(),
                            Forms\Components\Select::make('category')
                                ->label('Custom Category')
                                ->disabled(fn(Get $get) => !$get('playlist'))
                                ->helperText(fn(Get $get) => !$get('playlist') ? 'Select a custom playlist first.' : 'Select the category you would like to assign to the selected series to.')
                                ->options(function ($get) {
                                    $customList = CustomPlaylist::find($get('playlist'));
                                    return $customList ? $customList->tags()
                                        ->where('type', $customList->uuid . '-category')
                                        ->get()
                                        ->mapWithKeys(fn($tag) => [$tag->getAttributeValue('name') => $tag->getAttributeValue('name')])
                                        ->toArray() : [];
                                })
                                ->searchable(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $playlist = CustomPlaylist::findOrFail($data['playlist']);
                            foreach ($records as $record) {
                                // Sync the series to the custom playlist
                                // This will add the series to the playlist without detaching existing ones
                                // Prevents duplicates in the playlist
                                $playlist->series()->syncWithoutDetaching($record->series()->pluck('id'));
                                if ($data['category']) {
                                    $playlist->syncTagsWithType([$data['category']], $playlist->uuid);
                                }
                            }
                        })->after(function () {
                            FilamentNotification::make()
                                ->success()
                                ->title('Category series added to custom playlist')
                                ->body('The selected category series have been added to the chosen custom playlist.')
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-play')
                        ->modalIcon('heroicon-o-play')
                        ->modalDescription('Add the selected category series to the chosen custom playlist.')
                        ->modalSubmitActionLabel('Add now'),
                    Tables\Actions\BulkAction::make('move')
                        ->label('Move Series to Category')
                        ->form([
                            Forms\Components\Select::make('category')
                                ->required()
                                ->live()
                                ->label('Category')
                                ->helperText('Select the category you would like to move the series to.')
                                ->options(
                                    fn() => Category::query()
                                        ->with(['playlist'])
                                        ->where(['user_id' => auth()->id()])
                                        ->get(['name', 'id', 'playlist_id'])
                                        ->transform(fn($category) => [
                                            'id' => $category->id,
                                            'name' => $category->name . ' (' . $category->playlist->name . ')',
                                        ])->pluck('name', 'id')
                                )->searchable(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $category = Category::findOrFail($data['category']);
                            foreach ($records as $record) {
                                // Update the series to the new category
                                // This will change the category_id for the series in the database
                                // to reflect the new category
                                if ($category->playlist_id !== $record->playlist_id) {
                                    FilamentNotification::make()
                                        ->warning()
                                        ->title('Warning')
                                        ->body("Cannot move \"{$category->name}\" to \"{$record->name}\" as they belong to different playlists.")
                                        ->persistent()
                                        ->send();
                                    continue;
                                }
                                $record->series()->update([
                                    'category_id' => $category->id,
                                ]);
                                SyncPlaylistChildren::debounce($record->playlist, []);
                            }
                        })->after(function () {
                            FilamentNotification::make()
                                ->success()
                                ->title('Series moved to category')
                                ->body('The category series have been moved to the chosen category.')
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrows-right-left')
                        ->modalIcon('heroicon-o-arrows-right-left')
                        ->modalDescription('Move the category series to another category.')
                        ->modalSubmitActionLabel('Move now'),
                    Tables\Actions\BulkAction::make('process')
                        ->label('Fetch Series Metadata')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->action(function (Collection $records) {
                            foreach ($records as $record) {
                                foreach ($record->enabled_series as $series) {
                                    app('Illuminate\Contracts\Bus\Dispatcher')
                                        ->dispatch(new \App\Jobs\ProcessM3uImportSeriesEpisodes(
                                            playlistSeries: $series,
                                        ));
                                }
                            }
                        })->after(function () {
                            FilamentNotification::make()
                                ->success()
                                ->title('Series are being processed')
                                ->body('You will be notified once complete.')
                                ->duration(10000)
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-down-tray')
                        ->modalIcon('heroicon-o-arrow-down-tray')
                        ->modalDescription('Process series for selected category now? Only enabled series will be processed. This will fetch all episodes and seasons for the category series. This may take a while depending on the number of series in the category.')
                        ->modalSubmitActionLabel('Yes, process now'),
                    Tables\Actions\BulkAction::make('sync')
                        ->label('Sync Series .strm files')
                        ->action(function (Collection $records) {
                            foreach ($records as $record) {
                                foreach ($record->enabled_series as $series) {
                                    app('Illuminate\Contracts\Bus\Dispatcher')
                                        ->dispatch(new \App\Jobs\SyncSeriesStrmFiles(
                                            series: $series,
                                        ));
                                }
                            }
                        })->after(function () {
                            FilamentNotification::make()
                                ->success()
                                ->title('.strm files are being synced for selected category series. Only enabled series will be synced.')
                                ->body('You will be notified once complete.')
                                ->duration(10000)
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-document-arrow-down')
                        ->modalIcon('heroicon-o-document-arrow-down')
                        ->modalDescription('Sync selected category series .strm files now? This will generate .strm files for the selected series at the path set for the series.')
                        ->modalSubmitActionLabel('Yes, sync now'),
                    Tables\Actions\BulkAction::make('enable')
                        ->label('Enable selected')
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                $record->series()->update(['enabled' => true]);
                                SyncPlaylistChildren::debounce($record->playlist, []);
                            }
                        })->after(function () {
                            FilamentNotification::make()
                                ->success()
                                ->title('Selected category series enabled')
                                ->body('The selected category series have been enabled.')
                                ->send();
                        })
                        ->color('success')
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-check-circle')
                        ->modalIcon('heroicon-o-check-circle')
                        ->modalDescription('Enable the selected category series now?')
                        ->modalSubmitActionLabel('Yes, enable now'),
                    Tables\Actions\BulkAction::make('disable')
                        ->label('Disable selected')
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                $record->series()->update(['enabled' => false]);
                                SyncPlaylistChildren::debounce($record->playlist, []);
                            }
                        })->after(function () {
                            FilamentNotification::make()
                                ->success()
                                ->title('Selected category series disabled')
                                ->body('The selected category series have been disabled.')
                                ->send();
                        })
                        ->color('warning')
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-x-circle')
                        ->modalIcon('heroicon-o-x-circle')
                        ->modalDescription('Disable the selected category series now?')
                        ->modalSubmitActionLabel('Yes, disable now'),
                    // Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\SeriesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCategories::route('/'),
            // 'create' => Pages\CreateCategory::route('/create'),
            'view' => Pages\ViewCategory::route('/{record}'),
            // 'edit' => Pages\EditCategory::route('/{record}/edit'),
        ];
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Category Details')
                    ->columns(2)
                    ->schema([
                        Infolists\Components\TextEntry::make('name')
                            ->badge(),
                        Infolists\Components\TextEntry::make('playlist.name')
                            ->label('Playlist')
                            //->badge(),
                            ->url(fn($record) => PlaylistResource::getUrl('edit', ['record' => $record->playlist_id])),
                    ])
            ]);
    }
}
