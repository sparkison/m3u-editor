<?php

namespace App\Filament\Resources\Playlists\Resources\PlaylistSyncStatuses\Pages;

use App\Filament\Resources\Playlists\Resources\PlaylistSyncStatuses\PlaylistSyncStatusResource;
use App\Models\Playlist;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewPlaylistSyncStatus extends ViewRecord
{
    protected static string $resource = PlaylistSyncStatusResource::class;

    public function getParent(): Playlist
    {
        // For nested resources, the parent ID is available in the route parameters
        return Playlist::findOrFail(request()->route('playlist'));
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('back_to_sync_statuses')
                ->label('Back to Sync Statuses')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(function (): string {
                    return "/playlists/{$this->getParent()->id}/playlist-sync-statuses";
                }),
        ];
    }
}
