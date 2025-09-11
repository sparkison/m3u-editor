<?php

namespace App\Models;

use App\Models\Concerns\DispatchesPlaylistSync;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use DispatchesPlaylistSync;
    use HasFactory;

    public const SOURCE_INDEX = ['playlist_id', 'source_category_id'];

    protected function playlistSyncChanges(): array
    {
        $current = $this->source_category_id ?? 'cat-' . $this->id;
        $original = $this->getOriginal('source_category_id') ?? 'cat-' . $this->id;

        return ['categories' => array_unique(array_filter([
            $current,
            $original,
        ]))];
    }

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'source_category_id' => 'string',
        'user_id' => 'integer',
        'playlist_id' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    public function series(): HasMany
    {
        return $this->hasMany(Series::class);
    }

    public function enabled_series()
    {
        return $this->hasMany(Series::class)->where('enabled', true);
    }

    public function episodes(): HasMany
    {
        return $this->hasMany(Episode::class);
    }
}
