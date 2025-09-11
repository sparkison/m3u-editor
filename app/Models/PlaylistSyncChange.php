<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlaylistSyncChange extends Model
{
    use HasFactory;

    protected $fillable = [
        'playlist_id',
        'change_type',
        'item_ids',
    ];

    protected $casts = [
        'playlist_id' => 'integer',
        'item_ids' => 'array',
    ];

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }
}
