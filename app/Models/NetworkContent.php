<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class NetworkContent extends Model
{
    use HasFactory;

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

        // For Episodes, duration is stored in info
        if ($content instanceof Episode) {
            // First try duration_secs (already in seconds)
            if (isset($content->info['duration_secs']) && is_numeric($content->info['duration_secs'])) {
                return (int) $content->info['duration_secs'];
            }

            // Try duration field - could be HH:MM:SS format or seconds
            $duration = $content->info['duration'] ?? null;
            if ($duration) {
                return $this->parseDuration($duration);
            }

            return 0;
        }

        // For Channels (VOD), duration is in info.duration_secs or info.duration
        if ($content instanceof Channel) {
            // First check info directly on channel
            if (isset($content->info['duration_secs']) && is_numeric($content->info['duration_secs'])) {
                return (int) $content->info['duration_secs'];
            }

            $duration = $content->info['duration'] ?? null;
            if ($duration) {
                return $this->parseDuration($duration);
            }

            // Fallback to movie_data structure
            $secs = $content->movie_data['info']['duration_secs'] ?? null;
            if ($secs && is_numeric($secs)) {
                return (int) $secs;
            }

            $duration = $content->movie_data['info']['duration'] ?? null;
            if ($duration) {
                return $this->parseDuration($duration);
            }

            return 0;
        }

        return 0;
    }

    /**
     * Parse duration from various formats to seconds.
     */
    protected function parseDuration(mixed $duration): int
    {
        if (is_numeric($duration)) {
            return (int) $duration;
        }

        if (is_string($duration)) {
            // Handle HH:MM:SS or MM:SS format
            if (preg_match('/^(\d+):(\d+):(\d+)$/', $duration, $matches)) {
                return ((int) $matches[1] * 3600) + ((int) $matches[2] * 60) + (int) $matches[3];
            }
            if (preg_match('/^(\d+):(\d+)$/', $duration, $matches)) {
                return ((int) $matches[1] * 60) + (int) $matches[2];
            }
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

    /**
     * Find or create content entry for a network.
     */
    public static function findForNetwork(Network $network, Model $content): ?self
    {
        return self::where('network_id', $network->id)
            ->where('contentable_type', get_class($content))
            ->where('contentable_id', $content->id)
            ->first();
    }
}
