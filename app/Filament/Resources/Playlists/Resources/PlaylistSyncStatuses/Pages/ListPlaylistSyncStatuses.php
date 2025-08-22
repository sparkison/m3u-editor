<?php

namespace App\Filament\Resources\Playlists\Resources\PlaylistSyncStatuses\Pages;

use App\Filament\Resources\Playlists\Resources\PlaylistSyncStatuses\PlaylistSyncStatusResource;
use App\Models\Playlist;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPlaylistSyncStatuses extends ListRecords
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
            Actions\Action::make('back_to_playlist')
                ->label('Back to Playlist')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(function (): string {
                    return "/playlists/{$this->getParent()->id}";
                }),
            // Sync statuses are typically created automatically by the system
            // Actions\CreateAction::make(),
        ];
    }

    public function getTitle(): string
    {
        $playlist = $this->getParent();
        return "Sync Logs for {$playlist->name}";
    }

    protected function getHeaderWidgets(): array
    {
        return [];
    }
}
