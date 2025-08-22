<?php

namespace App\Filament\Resources\PlaylistSyncStatuses;

use App\Filament\Resources\Playlists\PlaylistResource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use App\Filament\Resources\PlaylistSyncStatuses\Pages\ListPlaylistSyncStatuses;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use App\Filament\Resources\PlaylistSyncStatuses\RelationManagers\LogsRelationManager;
use App\Filament\Resources\PlaylistSyncStatusResource\Pages;
use App\Filament\Resources\PlaylistSyncStatusResource\RelationManagers;
use App\Models\PlaylistSyncStatus;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

class PlaylistSyncStatusResource extends Resource
{
    protected static ?string $model = PlaylistSyncStatus::class;
    protected static ?string $label = 'Sync logs';

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-rectangle-stack';

    public static ?string $parentResource = PlaylistResource::class;


    public static function getRecordTitle(?Model $record): string|null|Htmlable
    {
        return $record->created_at;
        // return $record->name;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Synced')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('name')
                    ->label('Playlist Name')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('sync_stats.status')
                    ->label('Status')
                    ->searchable()
                    ->badge()
                    ->color(function ($state) {
                        return match ($state) {
                            'success' => 'success',
                            'canceled' => 'warning',
                            default => 'info',
                        };
                    })
                    ->default('success')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('added_channels_count')
                    ->label('Added Channels')
                    ->counts('addedChannels')
                    ->toggleable()
                    ->sortable(),
                TextColumn::make('removed_channels_count')
                    ->label('Removed Channels')
                    ->counts('removedChannels')
                    ->toggleable()
                    ->sortable(),
                TextColumn::make('added_groups_count')
                    ->label('Added Groups')
                    ->counts('addedGroups')
                    ->toggleable()
                    ->sortable(),
                TextColumn::make('removed_groups_count')
                    ->label('Removed Groups')
                    ->counts('removedGroups')
                    ->toggleable()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([])
            ->recordActions([
                DeleteAction::make()
                    ->modalDescription('Delete this sync log?')
                    ->modalSubmitActionLabel('Delete now')
                    ->button()->hiddenLabel(),
                ViewAction::make()
                    ->url(
                        fn(ListPlaylistSyncStatuses $livewire, Model $record): string => static::$parentResource::getUrl('playlist-sync-statuses.view', [
                            'record' => $record,
                            'parent' => $livewire->parent,
                        ])
                    )->button()->hiddenLabel(),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            LogsRelationManager::class,
        ];
    }

    public function getParentRelationshipKey(): string
    {
        return 'playlist_id';
    }
}
