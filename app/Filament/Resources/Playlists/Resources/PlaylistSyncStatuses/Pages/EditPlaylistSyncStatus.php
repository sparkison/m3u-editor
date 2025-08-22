<?php

namespace App\Filament\Resources\Playlists\Resources\PlaylistSyncStatuses\Pages;

use App\Filament\Resources\Playlists\Resources\PlaylistSyncStatuses\PlaylistSyncStatusResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPlaylistSyncStatus extends EditRecord
{
    protected static string $resource = PlaylistSyncStatusResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
