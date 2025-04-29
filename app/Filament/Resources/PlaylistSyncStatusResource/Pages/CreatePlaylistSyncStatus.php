<?php

namespace App\Filament\Resources\PlaylistSyncStatusResource\Pages;

use App\Filament\Resources\PlaylistSyncStatusResource;
use App\Traits\HasParentResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreatePlaylistSyncStatus extends CreateRecord
{
    use HasParentResource;

    protected static string $resource = PlaylistSyncStatusResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->previousUrl ?? static::getParentResource()::getUrl(
            name: 'playlist-sync-statuses.index',
            parameters: [
                'parent' => $this->parent,
            ]
        );
    }

    // This can be moved to Trait, but we are keeping it here
    //   to avoid confusion in case you mutate the data yourself
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Set the parent relationship key to the parent resource's ID.
        $data[$this->getParentRelationshipKey()] = $this->parent->id;

        return $data;
    }
}
