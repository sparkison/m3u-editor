<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PlaylistSyncStatusResource\Pages;
use App\Filament\Resources\PlaylistSyncStatusResource\RelationManagers;
use App\Models\Playlist;
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
    protected static ?string $parentResource = Playlist::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Playlist Name')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('removed_groups_count')
                    ->label('Removed Groups')
                    ->counts('removedGroups')
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('added_groups_count')
                    ->label('Added Groups')
                    ->counts('addedGroups')
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('removed_channels_count')
                    ->label('Removed Channels')
                    ->counts('removedChannels')
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('added_channels_count')
                    ->label('Added Channels')
                    ->counts('addedChannels')
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Synced')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make()->slideOver(),
                    Tables\Actions\DeleteAction::make(),
                ])->button()->hiddenLabel()
            ], position: Tables\Enums\ActionsPosition::BeforeCells)
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

    public function getParentRelationshipKey(): string
    {
        return 'playlist_id';
    }

    public static function getPages(): array
    {
        return [
            'view' => Pages\ViewPlaylistSyncStatus::route('/{record}'),
            'index' => Pages\ListPlaylistSyncStatuses::route('/'),
            // 'create' => Pages\CreatePlaylistSyncStatus::route('/create'),
            // 'edit' => Pages\EditPlaylistSyncStatus::route('/{record}/edit'),
        ];
    }
}
