<?php

namespace App\Models;

use App\Services\XtreamService;
use App\Traits\ShortUrlTrait;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PlaylistAlias extends Model
{
    use HasFactory;
    use ShortUrlTrait;

    protected $casts = [
        'xtream_config' => 'array',
        'enabled' => 'boolean',
        'priority' => 'integer',
    ];

    // Always eager load the related playlist or custom playlist
    // protected $with = ['playlist', 'customPlaylist'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    public function customPlaylist(): BelongsTo
    {
        return $this->belongsTo(CustomPlaylist::class);
    }

    /**
     * Get the effective playlist (either the main playlist or custom playlist)
     * This method returns the playlist that should be used for configuration
     */
    public function getEffectivePlaylist()
    {
        // Load relationships if not already loaded
        if (!$this->relationLoaded('playlist') && $this->playlist_id) {
            $this->load('playlist');
        }

        if (!$this->relationLoaded('customPlaylist') && $this->custom_playlist_id) {
            $this->load('customPlaylist');
        }

        return $this->playlist ?? $this->customPlaylist;
    }

    // Delegate most properties to the primary playlist
    public function __get($key)
    {
        // If the property exists on this model, return it
        if (array_key_exists($key, $this->attributes) || $this->hasGetMutator($key)) {
            return parent::__get($key);
        }

        // Handle relationship access directly
        if (in_array($key, ['xtream_status', 'playlist', 'customPlaylist', 'user'])) {
            return parent::__get($key);
        }

        // Get the effective playlist and delegate if it exists
        $effectivePlaylist = $this->getEffectivePlaylist();
        if ($effectivePlaylist && $effectivePlaylist->hasAttribute($key)) {
            return $effectivePlaylist->{$key};
        }

        // Fall back to parent behavior
        return parent::__get($key);
    }

    /**
     * Get channels relationship - delegates to effective playlist
     */
    public function channels()
    {
        $effectivePlaylist = $this->getEffectivePlaylist();
        return $effectivePlaylist ? $effectivePlaylist->channels() : null;
    }

    /**
     * Get series relationship - delegates to effective playlist
     */
    public function series()
    {
        $effectivePlaylist = $this->getEffectivePlaylist();
        return $effectivePlaylist ? $effectivePlaylist->series() : null;
    }

    /**
     * Get episodes relationship - delegates to effective playlist
     */
    public function episodes()
    {
        $effectivePlaylist = $this->getEffectivePlaylist();
        return $effectivePlaylist ? $effectivePlaylist->episodes() : null;
    }

    public function xtreamStatus(): Attribute
    {
        return Attribute::make(
            get: function ($value, $attributes) {
                if (!$this->xtream_config) {
                    return [];
                }
                try {
                    $xtream = XtreamService::make(xtream_config: $this->xtream_config);
                    if ($xtream) {
                        return Cache::remember(
                            "playlist_alias:{$attributes['id']}:xtream_status",
                            5, // cache for 5 seconds
                            function () use ($xtream) {
                                $userInfo = $xtream->userInfo();
                                return $userInfo ?: [];
                            }
                        );
                    }
                } catch (\Exception $e) {
                    Log::error('Failed to fetch metadata for Xtream playlist alias ' . $this->id, ['exception' => $e]);
                }

                return [];
            }
        );
    }
}
