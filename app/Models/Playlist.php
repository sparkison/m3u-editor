<?php

namespace App\Models;

use App\Enums\PlaylistChannelId;
use App\Enums\Status;
use App\Jobs\SyncPlaylistChildren;
use App\Traits\ShortUrlTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\Cache;

class Playlist extends Model
{
    use HasFactory;
    use ShortUrlTrait;

    /**
     * Cached result of children existence check.
     */
    protected ?bool $childExistsCache = null;

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'channels' => 'integer',
        'synced' => 'datetime',
        'uploads' => 'array',
        'user_id' => 'integer',
        'sync_time' => 'float',
        'processing' => 'boolean',
        'dummy_epg' => 'boolean',
        'import_prefs' => 'array',
        'groups' => 'array',
        'xtream_config' => 'array',
        'xtream_status' => 'array',
        'short_urls' => 'array',
        'proxy_options' => 'array',
        'short_urls_enabled' => 'boolean',
        'backup_before_sync' => 'boolean',
        'sync_logs_enabled' => 'boolean',
        'include_series_in_m3u' => 'boolean',
        'auto_fetch_series_metadata' => 'boolean',
        'parent_id' => 'integer',
        'status' => Status::class,
        'id_channel_by' => PlaylistChannelId::class,
    ];

    public function getFolderPathAttribute(): string
    {
        return "playlist/{$this->uuid}";
    }

    public function getFilePathAttribute(): string
    {
        return "playlist/{$this->uuid}/playlist.m3u";
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Determine if the playlist has child playlists, caching the result to
     * avoid repeated existence queries.
     */
    public function hasChildPlaylists(): bool
    {
        if ($this->childExistsCache === null) {
            $this->childExistsCache = $this->children()->exists();
        }

        return $this->childExistsCache;
    }

    /**
     * Clear the cached child-playlist flag.
     */
    public function refreshChildPlaylistCache(): void
    {
        $this->childExistsCache = null;
    }

    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }

    public function enabled_channels(): HasMany
    {
        return $this->channels()
            ->where('enabled', true);
    }

    public function live_channels(): HasMany
    {
        return $this->channels()
            ->where('is_vod', false);
    }

    public function enabled_live_channels(): HasMany
    {
        return $this->live_channels()
            ->where('enabled', true);
    }

    public function vod_channels(): HasMany
    {
        return $this->channels()
            ->where('is_vod', true);
    }

    public function enabled_vod_channels(): HasMany
    {
        return $this->vod_channels()
            ->where('enabled', true);
    }

    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
    }

    public function sourceGroups(): HasMany
    {
        return $this->hasMany(SourceGroup::class);
    }

    public function mergedPlaylists(): BelongsToMany
    {
        return $this->belongsToMany(MergedPlaylist::class, 'merged_playlist_playlist');
    }

    public function epgMaps(): HasMany
    {
        return $this->hasMany(EpgMap::class);
    }

    public function playlistAuths(): MorphToMany
    {
        return $this->morphToMany(PlaylistAuth::class, 'authenticatable');
    }

    public function postProcesses(): MorphToMany
    {
        return $this->morphToMany(PostProcess::class, 'processable');
    }

    public function syncStatuses(): HasMany
    {
        return $this->hasMany(PlaylistSyncStatus::class)
            ->orderBy('created_at', 'desc');
    }

    public function syncStatusesUnordered(): HasMany
    {
        return $this->hasMany(PlaylistSyncStatus::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function series(): HasMany
    {
        return $this->hasMany(Series::class);
    }

    public function enabled_series(): HasMany
    {
        return $this->series()->where('enabled', true);
    }

    public function seasons(): HasMany
    {
        return $this->hasMany(Season::class);
    }

    public function episodes(): HasMany
    {
        return $this->hasMany(Episode::class);
    }

    protected static function booted()
    {
        static::creating(function (Playlist $playlist) {
            if ($playlist->parent_id !== null) {
                $playlist->auto_sync = false;
            }
        });

        static::saved(function (Playlist $playlist) {
            if ($playlist->wasChanged('parent_id')) {
                if ($original = $playlist->getOriginal('parent_id')) {
                    self::find($original)?->refreshChildPlaylistCache();
                }

                if ($playlist->parent) {
                    $playlist->parent->refreshChildPlaylistCache();
                }
            } elseif ($playlist->parent) {
                $playlist->parent->refreshChildPlaylistCache();
            }
        });

        static::deleted(function (Playlist $playlist) {
            if ($playlist->parent) {
                $playlist->parent->refreshChildPlaylistCache();
            }
        });

        static::saved(function (Playlist $playlist) {
            if ($playlist->parent_id) {
                return;
            }

            if (! $playlist->hasChildPlaylists()) {
                return;
            }

            $structural = ['groups', 'channels', 'categories', 'series', 'seasons', 'episodes', 'uploads'];
            $changed = collect($structural)->some(fn ($field) => $playlist->wasChanged($field));
            if (! $changed) {
                return;
            }

            $lock = Cache::lock("sync-playlist-{$playlist->id}", 5);
            if ($lock->get()) {
                SyncPlaylistChildren::dispatch($playlist);
            }
        });
    }
}
