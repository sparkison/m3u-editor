<?php

namespace App\Filament\Resources\PlaylistSyncStatusResource\Pages;

use App\Filament\Resources\PlaylistSyncStatusResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPlaylistSyncStatuses extends ListRecords
{
    protected static string $resource = PlaylistSyncStatusResource::class;

    protected function getHeaderActions(): array
    {
        return [
            //Actions\CreateAction::make(),
        ];
    }
}
