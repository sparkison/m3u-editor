<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CategoryResource\Pages;
use App\Filament\Resources\CategoryResource\RelationManagers;
use App\Models\Category;
use App\Models\CustomPlaylist;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'name_internal'];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()
            ->where('user_id', auth()->id());
    }

    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

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
                    ->multiple()
                    ->preload()
                    ->searchable(),
            ])
            ->actions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\Action::make('add')
                        ->label('Add to custom playlist')
                        ->form([
                            Forms\Components\Select::make('playlist')
                                ->required()
                                ->label('Custom Playlist')
                                ->helperText('Select the custom playlist you would like to add the selected series to.')
                                ->options(CustomPlaylist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                                ->searchable(),
                        ])
                        ->action(function ($record, array $data): void {
                            $playlist = CustomPlaylist::findOrFail($data['playlist']);
                            $playlist->series()->syncWithoutDetaching($record->series()->pluck('id'));
                        })->after(function () {
                            Notification::make()
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
                        ->label('Move series to category')
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
                        })->after(function () {
                            Notification::make()
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
                        ->label('Process category series')
                        ->icon('heroicon-o-arrow-path')
                        ->action(function ($record) {
                            foreach ($record->enabled_series as $series) {
                                app('Illuminate\Contracts\Bus\Dispatcher')
                                    ->dispatch(new \App\Jobs\ProcessM3uImportSeriesEpisodes(
                                        playlistSeries: $series,
                                    ));
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Series are being processed')
                                ->body('You will be notified once complete.')
                                ->duration(10000)
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-path')
                        ->modalIcon('heroicon-o-arrow-path')
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
                            Notification::make()
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
                        })->after(function () {
                            Notification::make()
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
                        })->after(function () {
                            Notification::make()
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
                        ->label('Add to custom playlist')
                        ->form([
                            Forms\Components\Select::make('playlist')
                                ->required()
                                ->label('Custom Playlist')
                                ->helperText('Select the custom playlist you would like to add the selected category series to.')
                                ->options(CustomPlaylist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                                ->searchable(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $playlist = CustomPlaylist::findOrFail($data['playlist']);
                            foreach ($records as $record) {
                                // Sync the series to the custom playlist
                                // This will add the series to the playlist without detaching existing ones
                                // Prevents duplicates in the playlist
                                $playlist->series()->syncWithoutDetaching($record->series()->pluck('id'));
                            }
                        })->after(function () {
                            Notification::make()
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
                        ->label('Move series to category')
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
                                    Notification::make()
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
                            }
                        })->after(function () {
                            Notification::make()
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
                        ->label('Process series categories')
                        ->icon('heroicon-o-arrow-path')
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
                            Notification::make()
                                ->success()
                                ->title('Series are being processed')
                                ->body('You will be notified once complete.')
                                ->duration(10000)
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-path')
                        ->modalIcon('heroicon-o-arrow-path')
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
                            Notification::make()
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
                            }
                        })->after(function () {
                            Notification::make()
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
                            }
                        })->after(function () {
                            Notification::make()
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
