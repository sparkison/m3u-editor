<?php

namespace App\Filament\Resources\PlaylistSyncStatusResource\Pages;

use App\Traits\HasParentResource;
use App\Filament\Resources\PlaylistResource;
use App\Filament\Resources\PlaylistSyncStatusResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListPlaylistSyncStatuses extends ListRecords
{
    use HasParentResource;

    protected static string $resource = PlaylistSyncStatusResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Actions\CreateAction::make()
            //     ->url(
            //         fn(): string => static::getParentResource()::getUrl('playlist-sync-statuses.create', [
            //             'parent' => $this->parent,
            //         ])
            //     ),
        ];
    }

    /**
     * @deprecated Override the `table()` method to configure the table.
     */
    protected function getTableQuery(): ?Builder
    {
        return static::getResource()::getEloquentQuery()
            ->where('user_id', auth()->id());
    }
}
