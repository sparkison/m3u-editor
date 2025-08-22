<?php

namespace App\Filament\Resources\Playlists\Resources\PlaylistSyncStatuses;

use App\Filament\Resources\Playlists\PlaylistResource;
use App\Models\PlaylistSyncStatus;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Infolists;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;

class PlaylistSyncStatusResource extends Resource
{
    protected static ?string $model = PlaylistSyncStatus::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $parentResource = PlaylistResource::class;

    protected static ?string $recordTitleAttribute = 'created_at';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\TextInput::make('name')
                    ->required(),
                Forms\Components\Select::make('user_id')
                    ->relationship('user', 'name')
                    ->required(),
                Forms\Components\Select::make('playlist_id')
                    ->relationship('playlist', 'name')
                    ->required(),
                Forms\Components\TextInput::make('sync_stats'),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Infolists\Components\TextEntry::make('name'),
                Infolists\Components\TextEntry::make('user.name'),
                Infolists\Components\TextEntry::make('playlist.name'),
                Infolists\Components\TextEntry::make('created_at')
                    ->dateTime(),
                Infolists\Components\TextEntry::make('updated_at')
                    ->dateTime(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('created_at')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('playlist.name')
                    ->searchable(),
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
            ->recordActions([
                Actions\ViewAction::make(),
                Actions\EditAction::make(),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
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
            'create' => Pages\CreatePlaylistSyncStatus::route('/create'),
            'view' => Pages\ViewPlaylistSyncStatus::route('/{record}'),
            'edit' => Pages\EditPlaylistSyncStatus::route('/{record}/edit'),
        ];
    }
}
