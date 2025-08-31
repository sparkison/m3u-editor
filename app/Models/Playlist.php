<?php

namespace App\Models;

use App\Enums\PlaylistChannelId;
use App\Enums\Status;
use App\Traits\ShortUrlTrait;
use App\Jobs\ProcessM3uImport;
use AshAllenDesign\ShortURL\Models\ShortURL;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Playlist extends Model
{
    use HasFactory;
    use ShortUrlTrait;

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
        'status' => Status::class,
        'id_channel_by' => PlaylistChannelId::class,
        'paired_playlist_id' => 'integer',
        'parent_playlist_id' => 'integer'
    ];

    protected static function booted(): void
    {
        static::saved(function (self $playlist): void {
            if ($playlist->wasChanged('parent_playlist_id')) {
                if ($playlist->parent_playlist_id) {
                    $playlist->parentPlaylist?->syncChildPlaylists();
                } else {
                    $playlist->groups()->withoutGlobalScopes()->delete();
                    $playlist->categories()->withoutGlobalScopes()->delete();
                    $playlist->series()->withoutGlobalScopes()->delete();
                    $playlist->channels()->withoutGlobalScopes()->delete();

                    dispatch(new ProcessM3uImport($playlist, force: true));
                }
            }
        });
    }

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

    public function parentPlaylist(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_playlist_id');
    }

    public function childPlaylists(): HasMany
    {
        return $this->hasMany(self::class, 'parent_playlist_id');
    }

    public function pairedPlaylist(): BelongsTo
    {
        return $this->belongsTo(self::class, 'paired_playlist_id');
    }

    /**
     * Determine if this playlist has the same content as another.
     */
    public function isIdenticalTo(self $other): bool
    {
        return md5(json_encode($this->snapshot())) === md5(json_encode($other->snapshot()));
    }

    /**
     * Pair this playlist with another one if their contents are identical.
     */
    public function pairWith(self $other): bool
    {
        if (!$this->isIdenticalTo($other)) {
            return false;
        }

        self::withoutEvents(function () use ($other) {
            $this->paired_playlist_id = $other->id;
            $this->save();
            $other->paired_playlist_id = $this->id;
            $other->save();
        });

        $this->syncPairedPlaylist();

        return true;
    }

    /**
     * Create a snapshot of the playlist's content for comparison.
     */
    private function snapshot(): array
    {
        return [
            'groups' => $this->groups()->orderBy('name')->pluck('name')->toArray(),
            'categories' => $this->categories()->orderBy('name')->pluck('name')->toArray(),
            'series' => $this->series()->orderBy('name')->pluck('name')->toArray(),
            'channels' => $this->channels()->orderBy('name')->pluck('name')->toArray(),
        ];
    }

    /**
     * Synchronize this playlist's data with its paired playlist.
     */
    public function syncPairedPlaylist(): void
    {
        $paired = $this->pairedPlaylist;
        if (!$paired) {
            return;
        }

        self::withoutEvents(function () use ($paired) {
            $paired->forceFill(array_filter($this->only([
                'name',
                'url',
                'status',
                'prefix',
                'channels',
                'synced',
                'errors',
                'available_streams',
                'groups',
                'uploads',
                'short_urls_enabled',
                'enable_proxy',
                'auto_sync',
                'sync_interval',
                'sync_time',
                'processing',
                'dummy_epg',
                'import_prefs',
                'xtream_config',
                'xtream_status',
                'short_urls',
                'proxy_options',
                'backup_before_sync',
                'sync_logs_enabled',
                'id_channel_by',
            ]), fn($value) => !is_null($value)));
            $paired->save();

            // Sync groups
            $paired->groups()->withoutGlobalScopes()->delete();
            $groupMap = [];
            foreach ($this->groups()->get() as $group) {
                $newGroup = $group->replicate();
                $newGroup->playlist_id = $paired->id;
                $newGroup->save();
                $groupMap[$group->id] = $newGroup->id;
            }

            // Sync categories
            $paired->categories()->withoutGlobalScopes()->delete();
            $categoryMap = [];
            foreach ($this->categories()->get() as $category) {
                $newCategory = $category->replicate();
                $newCategory->playlist_id = $paired->id;
                $newCategory->save();
                $categoryMap[$category->id] = $newCategory->id;
            }

            // Sync series
            $paired->series()->withoutGlobalScopes()->delete();
            foreach ($this->series()->get() as $series) {
                $newSeries = $series->replicate();
                $newSeries->playlist_id = $paired->id;
                if ($series->category_id && isset($categoryMap[$series->category_id])) {
                    $newSeries->category_id = $categoryMap[$series->category_id];
                }
                $newSeries->save();
            }

            // Sync channels
            $paired->channels()->withoutGlobalScopes()->delete();
            foreach ($this->channels()->get() as $channel) {
                $newChannel = $channel->replicate();
                $newChannel->playlist_id = $paired->id;
                if ($channel->group_id && isset($groupMap[$channel->group_id])) {
                    $newChannel->group_id = $groupMap[$channel->group_id];
                }
                $newChannel->save();
            }
        });

        $this->syncChildPlaylists();
    }

    public function syncChildPlaylists(): void
    {
        foreach ($this->childPlaylists as $child) {
            self::withoutEvents(function () use ($child) {
                $child->forceFill(array_filter($this->only([
                    'name',
                    'url',
                    'status',
                    'prefix',
                    'channels',
                    'synced',
                    'errors',
                    'available_streams',
                    'groups',
                    'uploads',
                    'short_urls_enabled',
                    'enable_proxy',
                    'auto_sync',
                    'sync_interval',
                    'sync_time',
                    'processing',
                    'dummy_epg',
                    'import_prefs',
                    'xtream_config',
                    'xtream_status',
                    'short_urls',
                    'proxy_options',
                    'backup_before_sync',
                    'sync_logs_enabled',
                    'id_channel_by',
                ]), fn($value) => !is_null($value)));
                $child->save();

                $child->groups()->withoutGlobalScopes()->delete();
                $groupMap = [];
                foreach ($this->groups()->get() as $group) {
                    $newGroup = $group->replicate();
                    $newGroup->playlist_id = $child->id;
                    $newGroup->save();
                    $groupMap[$group->id] = $newGroup->id;
                }

                $child->categories()->withoutGlobalScopes()->delete();
                $categoryMap = [];
                foreach ($this->categories()->get() as $category) {
                    $newCategory = $category->replicate();
                    $newCategory->playlist_id = $child->id;
                    $newCategory->save();
                    $categoryMap[$category->id] = $newCategory->id;
                }

                $child->series()->withoutGlobalScopes()->delete();
                foreach ($this->series()->get() as $series) {
                    $newSeries = $series->replicate();
                    $newSeries->playlist_id = $child->id;
                    if ($series->category_id && isset($categoryMap[$series->category_id])) {
                        $newSeries->category_id = $categoryMap[$series->category_id];
                    }
                    $newSeries->save();
                }

                $child->channels()->withoutGlobalScopes()->delete();
                foreach ($this->channels()->get() as $channel) {
                    $newChannel = $channel->replicate();
                    $newChannel->playlist_id = $child->id;
                    if ($channel->group_id && isset($groupMap[$channel->group_id])) {
                        $newChannel->group_id = $groupMap[$channel->group_id];
                    }
                    $newChannel->save();
                }
            });
        }
    }
}
