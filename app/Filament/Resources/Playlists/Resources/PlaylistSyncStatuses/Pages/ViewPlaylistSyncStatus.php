<?php

namespace App\Filament\Resources\Playlists\Resources\PlaylistSyncStatuses\Pages;

use App\Filament\Resources\Playlists\Resources\PlaylistSyncStatuses\PlaylistSyncStatusResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewPlaylistSyncStatus extends ViewRecord
{
    protected static string $resource = PlaylistSyncStatusResource::class;

    protected function getHeaderActions(): array
    {
        return [
            //
        ];
    }
}
