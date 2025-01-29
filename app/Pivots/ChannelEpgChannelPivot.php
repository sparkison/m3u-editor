<?php

namespace App\Pivots;

use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\Playlist;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\Pivot;

class ChannelEpgChannelPivot extends Pivot
{
    protected $table = 'channel_epg_channel';

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function enabledChannels(): BelongsTo
    {
        return $this->channels()->where('enabled', true);
    }

    public function epgChannel(): BelongsTo
    {
        return $this->belongsTo(EpgChannel::class);
    }

    public function playlists(): HasManyThrough
    {
        return $this->hasManyThrough(
            Playlist::class,
            Channel::class
        );
    }

    public function epgs(): HasManyThrough
    {
        return $this->hasManyThrough(
            Epg::class,
            EpgChannel::class
        );
    }
}
