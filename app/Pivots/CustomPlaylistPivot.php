<?php

namespace App\Pivots;

use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Playlist;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\Pivot;

class CustomPlaylistPivot extends Pivot
{
    protected $table = 'channel_custom_playlist';

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function enabledChannels(): HasManyThrough
    {
        return $this->channels()->where('enabled', true);
    }

    public function customPlaylist(): BelongsTo
    {
        return $this->belongsTo(CustomPlaylist::class);
    }

    public function playlists(): HasManyThrough
    {
        return $this->hasManyThrough(
            Playlist::class,
            Channel::class
        );
    }
}
