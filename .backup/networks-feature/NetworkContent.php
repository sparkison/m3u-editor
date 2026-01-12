<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class NetworkContent extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'network_content';

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'network_id' => 'integer',
        'sort_order' => 'integer',
        'weight' => 'integer',
    ];

    /**
     * Get the network this content belongs to.
     */
    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class);
    }

    /**
     * Get the content item (Episode or Channel/VOD).
     */
    public function contentable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the duration of this content in seconds.
     */
    public function getDurationSecondsAttribute(): int
    {
        $content = $this->contentable;

        if (! $content) {
            return 0;
        }

        // For Episodes, duration is stored in info.duration (seconds)
        if ($content instanceof Episode) {
            return (int) ($content->info['duration'] ?? 0);
        }

        // For Channels (VOD), duration is in movie_data.info.duration_secs
        if ($content instanceof Channel) {
            return (int) ($content->movie_data['info']['duration_secs'] ?? 0);
        }

        return 0;
    }

    /**
     * Get the title of this content.
     */
    public function getTitleAttribute(): string
    {
        $content = $this->contentable;

        if (! $content) {
            return 'Unknown';
        }

        if ($content instanceof Episode) {
            $series = $content->series;
            $seasonNum = $content->season ?? 1;
            $episodeNum = $content->episode_num ?? 1;

            return $series
                ? "{$series->name} S{$seasonNum}E{$episodeNum} - {$content->title}"
                : $content->title;
        }

        return $content->name ?? $content->title ?? 'Unknown';
    }
}
