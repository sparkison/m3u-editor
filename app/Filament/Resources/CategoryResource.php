<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CategoryResource\Pages;
use App\Filament\Resources\CategoryResource\RelationManagers;
use App\Models\Category;
use Filament\Forms;
use Filament\Forms\Form;
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

    protected static ?string $navigationGroup = 'Playlist';

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
                $query->withCount('enabled_series');
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
                    ->counts('series')
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
                ])->color('primary')->button()->hiddenLabel()->size('sm'),
            ], position: Tables\Enums\ActionsPosition::BeforeCells)
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    // Tables\Actions\BulkAction::make('process')
                    //     ->label('Process Selected Series')
                    //     ->icon('heroicon-o-arrow-path')
                    //     ->action(function ($records) {
                    //         foreach ($records as $record) {
                    //             app('Illuminate\Contracts\Bus\Dispatcher')
                    //                 ->dispatch(new \App\Jobs\ProcessM3uImportSeriesEpisodes(
                    //                     playlistSeries: $record,
                    //                 ));
                    //         }
                    //     })->after(function () {
                    //         Notification::make()
                    //             ->success()
                    //             ->title('Series are being processed')
                    //             ->body('You will be notified once complete.')
                    //             ->duration(10000)
                    //             ->send();
                    //     })
                    //     ->requiresConfirmation()
                    //     ->icon('heroicon-o-arrow-path')
                    //     ->modalIcon('heroicon-o-arrow-path')
                    //     ->modalDescription('Process selected series now? This will fetch all episodes and seasons for this series. This may take a while depending on the number of series selected.')
                    //     ->modalSubmitActionLabel('Yes, process now'),
                    // Tables\Actions\BulkAction::make('sync')
                    //     ->label('Sync Series .strm files')
                    //     ->action(function ($records) {
                    //         foreach ($records as $record) {
                    //             app('Illuminate\Contracts\Bus\Dispatcher')
                    //                 ->dispatch(new \App\Jobs\SyncSeriesStrmFiles(
                    //                     series: $record,
                    //                 ));
                    //         }
                    //     })->after(function () {
                    //         Notification::make()
                    //             ->success()
                    //             ->title('.strm files are being synced for selected series')
                    //             ->body('You will be notified once complete.')
                    //             ->duration(10000)
                    //             ->send();
                    //     })
                    //     ->requiresConfirmation()
                    //     ->icon('heroicon-o-document-arrow-down')
                    //     ->modalIcon('heroicon-o-document-arrow-down')
                    //     ->modalDescription('Sync selected series .strm files now? This will generate .strm files for the selected series at the path set for the series.')
                    //     ->modalSubmitActionLabel('Yes, sync now'),
                    // Tables\Actions\BulkAction::make('enable')
                    //     ->label('Enable selected')
                    //     ->action(function (Collection $records): void {
                    //         foreach ($records as $record) {
                    //             $record->update([
                    //                 'enabled' => true,
                    //             ]);
                    //         }
                    //     })->after(function () {
                    //         Notification::make()
                    //             ->success()
                    //             ->title('Selected series enabled')
                    //             ->body('The selected series have been enabled.')
                    //             ->send();
                    //     })
                    //     ->color('success')
                    //     ->deselectRecordsAfterCompletion()
                    //     ->requiresConfirmation()
                    //     ->icon('heroicon-o-check-circle')
                    //     ->modalIcon('heroicon-o-check-circle')
                    //     ->modalDescription('Enable the selected channel(s) now?')
                    //     ->modalSubmitActionLabel('Yes, enable now'),
                    // Tables\Actions\BulkAction::make('disable')
                    //     ->label('Disable selected')
                    //     ->action(function (Collection $records): void {
                    //         foreach ($records as $record) {
                    //             $record->update([
                    //                 'enabled' => false,
                    //             ]);
                    //         }
                    //     })->after(function () {
                    //         Notification::make()
                    //             ->success()
                    //             ->title('Selected series disabled')
                    //             ->body('The selected series have been disabled.')
                    //             ->send();
                    //     })
                    //     ->color('warning')
                    //     ->deselectRecordsAfterCompletion()
                    //     ->requiresConfirmation()
                    //     ->icon('heroicon-o-x-circle')
                    //     ->modalIcon('heroicon-o-x-circle')
                    //     ->modalDescription('Disable the selected channel(s) now?')
                    //     ->modalSubmitActionLabel('Yes, disable now'),
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
            'view' => Pages\ViewCategory::route('/{record}'),
            // 'create' => Pages\CreateCategory::route('/create'),
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
