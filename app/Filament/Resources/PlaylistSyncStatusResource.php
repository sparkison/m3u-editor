<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PlaylistSyncStatusResource\Pages;
use App\Filament\Resources\PlaylistSyncStatusResource\RelationManagers;
use App\Models\PlaylistSyncStatus;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PlaylistSyncStatusResource extends Resource
{
    protected static ?string $model = PlaylistSyncStatus::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                //
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                //
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
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
            RelationManagers\LogsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'view' => Pages\ViewPlaylistSyncStatus::route('/{record}'),
            // 'index' => Pages\ListPlaylistSyncStatuses::route('/'),
            // 'create' => Pages\CreatePlaylistSyncStatus::route('/create'),
            // 'edit' => Pages\EditPlaylistSyncStatus::route('/{record}/edit'),
        ];
    }
}
