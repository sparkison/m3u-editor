<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Channel extends Model
{
    use HasFactory;

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'enabled' => 'boolean',
        'channel' => 'integer',
        'shift' => 'integer',
        'user_id' => 'integer',
        'playlist_id' => 'integer',
        'group_id' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function epgChannel(): BelongsTo
    {
        return $this->belongsTo(EpgChannel::class);
    }

    public function customPlaylists(): BelongsToMany
    {
        return $this->belongsToMany(CustomPlaylist::class, 'channel_custom_playlist');
    }
}
