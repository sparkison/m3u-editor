<?php

namespace App\Models;

use App\Jobs\SyncSeriesStrmFiles;
use App\Models\Concerns\DispatchesPlaylistSync;
use App\Services\XtreamService;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Tags\HasTags;

class Series extends Model
{
    use DispatchesPlaylistSync;
    use HasFactory;
    use HasTags;

    public const SOURCE_INDEX = ['playlist_id', 'source_series_id'];

    protected function playlistSyncChanges(): array
    {
        $source = $this->source_series_id ?? 'series-'.$this->id;

        return ['series' => [$source]];
    }

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'new' => 'boolean',
        'source_category_id' => 'string',
        'source_series_id' => 'string',
        'user_id' => 'integer',
        'playlist_id' => 'integer',
        'category_id' => 'integer',
        // 'release_date' => 'date', // Not always well formed date, don't attempt to cast
        'rating_5based' => 'integer',
        'enabled' => 'boolean',
        'backdrop_path' => 'array',
        'metadata' => 'array',
        'sync_settings' => 'array',
        'last_metadata_fetch' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function seasons(): HasMany
    {
        return $this->hasMany(Season::class);
    }

    public function episodes(): HasMany
    {
        return $this->hasMany(Episode::class);
    }

    public function enabled_episodes(): HasMany
    {
        return $this->hasMany(Episode::class)->where('enabled', true);
    }

    public function fetchMetadata()
    {
        try {
            $playlist = $this->playlist;
            $xtream = XtreamService::make($playlist);

            if (! $xtream) {
                Notification::make()
                    ->danger()
                    ->title('Series metadata sync failed')
                    ->body('Unable to connect to Xtream API provider to get series info, unable to fetch metadata.')
                    ->broadcast($playlist->user)
                    ->sendToDatabase($playlist->user);

                return;
            }

            $detail = $xtream->getSeriesInfo($this->source_series_id);
            $info = $detail['info'] ?? [];
            $eps = $detail['episodes'] ?? [];
            $batchNo = Str::orderedUuid()->toString();

            // If episodes found, process them
            if (count($eps) > 0) {
                // Process the series episodes
                $playlistCategory = $this->category;
                $episodeCount = 0;
                foreach ($eps as $season => $episodes) {
                    // Check if the season exists in the playlist
                    $playlistSeason = $this->seasons()
                        ->where('season_number', $season)
                        ->first();

                    if (! $playlistSeason) {
                        // Create the season if it doesn't exist
                        $seasonInfo = $info['seasons'][$season] ?? [];
                        $playlistSeason = $this->seasons()->create([
                            'season_number' => $season,
                            'name' => 'Season '.str_pad($season, 2, '0', STR_PAD_LEFT),
                            'source_season_id' => $seasonInfo['id'] ?? null,
                            'episode_count' => $seasonInfo['episode_count'] ?? 0,
                            'cover' => $seasonInfo['cover'] ?? null,
                            'cover_big' => $seasonInfo['cover_big'] ?? null,
                            'user_id' => $playlist->user_id,
                            'playlist_id' => $playlist->id,
                            'series_id' => $this->id,
                            'category_id' => $playlistCategory->id,
                            'import_batch_no' => $batchNo,
                        ]);
                    } else {
                        // Update the season if it exists
                        $playlistSeason->update([
                            'new' => false,
                            'source_season_id' => $seasonInfo['id'] ?? null,
                            'category_id' => $playlistCategory->id,
                            'episode_count' => $seasonInfo['episode_count'] ?? 0,
                            'cover' => $seasonInfo['cover'] ?? null,
                            'cover_big' => $seasonInfo['cover_big'] ?? null,
                            'series_id' => $this->id,
                            'import_batch_no' => $batchNo,
                        ]);
                    }

                    // Process each episode in the season
                    $bulk = [];
                    foreach ($episodes as $ep) {
                        $episodeCount++;
                        $url = $xtream->buildSeriesUrl($ep['id'], $ep['container_extension']);
                        $title = preg_match('/S\d{2}E\d{2} - (.*)/', $ep['title'], $m) ? $m[1] : null;
                        if (! $title) {
                            $title = $ep['title'] ?? "Episode {$ep['episode_num']}";
                        }
                        $bulk[] = [
                            'title' => $title,
                            'source_episode_id' => (int) $ep['id'],
                            'import_batch_no' => $batchNo,
                            'user_id' => $playlist->user_id,
                            'playlist_id' => $playlist->id,
                            'series_id' => $this->id,
                            'season_id' => $playlistSeason->id,
                            'episode_num' => (int) $ep['episode_num'],
                            'container_extension' => $ep['container_extension'],
                            'custom_sid' => $ep['custom_sid'] ?? null,
                            'added' => $ep['added'] ?? null,
                            'season' => (int) $season,
                            'url' => $url,
                            'info' => json_encode([
                                'release_date' => $ep['info']['release_date'] ?? null,
                                'plot' => $ep['info']['plot'] ?? null,
                                'duration_secs' => $ep['info']['duration_secs'] ?? null,
                                'duration' => $ep['info']['duration'] ?? null,
                                'movie_image' => $ep['info']['movie_image'] ?? null,
                                'bitrate' => $ep['info']['bitrate'] ?? 0,
                                'rating' => $ep['info']['rating'] ?? null,
                                'season' => $ep['info']['season'] ?? null,
                                'tmdb_id' => $ep['info']['tmdb_id'] ?? null,
                                'cover_big' => $ep['info']['cover_big'] ?? null,
                            ]),
                        ];
                    }

                    // Upsert the episodes in bulk
                    Episode::upsert(
                        $bulk,
                        uniqueBy: ['source_episode_id', 'playlist_id'],
                        update: [
                            'title',
                            'import_batch_no',
                            'episode_num',
                            'container_extension',
                            'custom_sid',
                            'added',
                            'season',
                            'url',
                            'info',
                        ]
                    );
                }

                // Update last fetched timestamp for the series
                $this->update([
                    'last_metadata_fetch' => now(),
                ]);

                // Dispatch the job to sync .strm files
                dispatch(new SyncSeriesStrmFiles(series: $this, notify: false));

                return $episodeCount;
            }
        } catch (\Exception $e) {
            Log::error('Failed to fetch metadata for series '.$this->id, ['exception' => $e]);
        }

        return false;
    }
}
