<?php

namespace App\Pivots;

use App\Models\MergedPlaylist;
use App\Models\CustomPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class AuthenticatablePivot extends Pivot
{
    protected $table = 'authenticatables';

    public function playlistAuths(): BelongsTo
    {
        return $this->belongsTo(PlaylistAuth::class);
    }

    public function playlists(): BelongsTo
    {
        return $this->belongsTo(Playlist::class, 'authenticatable_id')
            ->where('authenticatable_type', Playlist::class);
    }

    public function customPlaylists(): BelongsTo
    {
        return $this->belongsTo(CustomPlaylist::class, 'authenticatable_id')
            ->where('authenticatable_type', CustomPlaylist::class);
    }

    public function mergedPlaylists(): BelongsTo
    {
        return $this->belongsTo(MergedPlaylist::class, 'authenticatable_id')
            ->where('authenticatable_type', MergedPlaylist::class);
    }
}
