<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MergedPlaylistResource\Pages;
use App\Filament\Resources\MergedPlaylistResource\RelationManagers;
use App\Forms\Components\PlaylistM3uUrl;
use App\Models\MergedPlaylist;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class MergedPlaylistResource extends Resource
{
    protected static ?string $navigationIcon = 'heroicon-o-play';

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
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                // Tables\Columns\TextColumn::make('channels_count')
                //     ->label('Channels')
                //     ->counts('playlists.channels')
                //     ->sortable(),
                // Tables\Columns\TextColumn::make('enabled_channels_count')
                //     ->label('Enabled Channels')
                //     ->counts('playlists.enabled_channels')
                //     ->sortable(),
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
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\PlaylistsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMergedPlaylists::route('/'),
            // 'create' => Pages\CreateMergedPlaylist::route('/create'),
            'edit' => Pages\EditMergedPlaylist::route('/{record}/edit'),
        ];
    }

    public static function getForm(): array
    {
        return [
            Forms\Components\TextInput::make('name')
                ->required()
                ->columnSpan('full')
                ->helperText('Enter the name of the playlist. Internal use only.'),
            PlaylistM3uUrl::make('m3u_url')
                ->hiddenOn(['create']) // hide this field on the create form
                ->columnSpan(2)
                ->dehydrated(false) // don't save the value in the database
                ->helperText('Your generated m3u playlist, based on the playlist configurtation. Only enabled channels will be included.'),
        ];
    }
}
