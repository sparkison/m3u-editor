<?php

namespace App\Filament\Resources\PlaylistSyncStatuses\Pages;

use Filament\Actions\ViewAction;
use Filament\Actions\DeleteAction;
use App\Filament\Resources\PlaylistSyncStatuses\PlaylistSyncStatusResource;
use App\Traits\HasParentResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPlaylistSyncStatus extends EditRecord
{
    use HasParentResource;

    protected static string $resource = PlaylistSyncStatusResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            //Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->previousUrl ?? static::getParentResource()::getUrl(
            name: 'playlist-sync-statuses.index',
            parameters: [
                'parent' => $this->parent,
            ]
        );
    }

    protected function configureDeleteAction(DeleteAction $action): void
    {
        $resource = static::getResource();

        $action->authorize($resource::canDelete($this->getRecord()))
            ->successRedirectUrl(static::getParentResource()::getUrl('playlist-sync-statuses.index', [
                'parent' => $this->parent,
            ]));
    }
}
