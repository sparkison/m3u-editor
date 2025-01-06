<?php

namespace App\Filament\Resources;

use App\Enums\PlaylistStatus;
use App\Filament\Resources\PlaylistResource\Pages;
use App\Filament\Resources\PlaylistResource\RelationManagers;
use App\Forms\Components\PlaylistM3uUrl;
use App\Models\Playlist;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\Column;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PlaylistResource extends Resource
{
    protected static ?string $model = Playlist::class;

    protected static ?string $navigationIcon = 'heroicon-o-play';

    public static function getNavigationSort(): ?int
    {
        return 1;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema(self::getForm());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll()
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('url')
                    ->label('Playlist URL')
                    ->searchable(),
                Tables\Columns\TextColumn::make('groups_count')
                    ->label('Groups')
                    ->counts('groups')
                    ->sortable(),
                Tables\Columns\TextColumn::make('channels_count')
                    ->label('Channels')
                    ->counts('channels')
                    ->sortable(),
                Tables\Columns\TextColumn::make('enabled_channels_count')
                    ->label('Enabled Channels')
                    ->counts('enabled_channels')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->sortable()
                    ->badge()
                    ->color(fn(PlaylistStatus $state) => $state->getColor()),
                Tables\Columns\TextColumn::make('synced')
                    ->label('Last Synced')
                    ->dateTime()
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
                //
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make()
                        ->after(function () {
                            Notification::make()
                                ->success()
                                ->title('New playlist added')
                                ->body('Playlist is currently processing in the background. Depending on the size of your playlist, this may take a while.')
                                ->duration(10000)
                                ->send();
                        }),
                    Tables\Actions\Action::make('process')
                        ->label('Process')
                        ->icon('heroicon-o-arrow-path')
                        ->action(fn($record) => app('Illuminate\Contracts\Bus\Dispatcher')->dispatch(new \App\Jobs\ProcessM3uImport($record)))->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Playlist is processing')
                                ->body('Playlist is being processed in the background. Depending on the size of your playlist, this may take a while.')
                                ->duration(10000)
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-path')
                        ->modalIcon('heroicon-o-arrow-path')
                        ->modalDescription('Process playlist now?')
                        ->modalSubmitActionLabel('Yes, process now'),
                    Tables\Actions\Action::make('Download M3U')
                        ->label('Download M3U')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->url(fn(Playlist $record) => route('playlists.generate', ['uuid' => $record->uuid]))
                        ->openUrlInNewTab(),
                    Tables\Actions\DeleteAction::make(),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\BulkAction::make('process')
                        ->label('Process selected')
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                dispatch(new \App\Jobs\ProcessM3uImport($record));
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
                        ->modalSubmitActionLabel('Yes, process now')
                ]),
            ])->checkIfRecordIsSelectableUsing(
                fn($record): bool => $record->status !== PlaylistStatus::Processing,
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
            'index' => Pages\ListPlaylists::route('/'),
            //'create' => Pages\CreatePlaylist::route('/create'),
            //'edit' => Pages\EditPlaylist::route('/{record}/edit'),
        ];
    }

    public static function getForm(): array
    {
        return [
            Forms\Components\TextInput::make('name')
                ->required()
                ->helperText('Enter the name of the playlist. Internal use only.'),
            Forms\Components\TextInput::make('url')
                ->label('Playlist URL')
                ->url()
                ->prefixIcon('heroicon-m-globe-alt')
                ->required()
                ->helperText('Enter the URL of the playlist file. If changing URL, the playlist will be re-imported. Use with caution as this could lead to data loss if the new playlist differs from the old one.'),
            PlaylistM3uUrl::make('m3u_url')
                ->hiddenOn(['create']) // hide this field on the create form
                ->columnSpan(2)
                ->dehydrated(false) // don't save the value in the database
                ->helperText('Your generated m3u playlist, based on the playlist configurtation. Only enabled channels will be included.'),
        ];
    }
}
