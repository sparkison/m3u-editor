<?php

namespace App\Models\Concerns;

use App\Jobs\SyncPlaylistChildren;

/**
 * Dispatches the SyncPlaylistChildren job when models belonging to a parent
 * playlist change. This trait expects the Playlist model to cache the result
 * of its `children()->exists()` check via `hasChildPlaylists()` and to clear
 * that cache when child playlists are added or removed.
 */
trait DispatchesPlaylistSync
{
    protected static function bootDispatchesPlaylistSync(): void
    {
        $dispatch = function ($model): void {
            $playlist = $model->playlist;
            if ($playlist && $playlist->parent_id === null && $playlist->hasChildPlaylists()) {
                SyncPlaylistChildren::debounce($playlist, $model->playlistSyncChanges());
            }
        };

        static::saved($dispatch);
        static::deleted($dispatch);
    }

    /**
     * Return the change payload for SyncPlaylistChildren.
     *
     * @return array<string, array<int, string>>
     */
    protected function playlistSyncChanges(): array
    {
        return [];
    }
}
