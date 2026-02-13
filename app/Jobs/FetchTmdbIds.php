<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Series;
use App\Models\User;
use App\Services\TmdbService;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Job to fetch TMDB/TVDB/IMDB IDs for VOD channels and Series.
 * Can process a single item or a batch of items.
 */
class FetchTmdbIds implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public $timeout = 60 * 30; // 30 minutes max for batch processing

    protected int $foundCount = 0;

    protected int $notFoundCount = 0;

    protected int $skippedCount = 0;

    protected int $errorCount = 0;

    /**
     * Create a new job instance.
     *
     * @param  Collection|array|null  $vodChannelIds  VOD channel IDs to process (legacy support)
     * @param  Collection|array|null  $seriesIds  Series IDs to process (legacy support)
     * @param  int|null  $vodPlaylistId  Playlist ID for VOD channels
     * @param  int|null  $seriesPlaylistId  Playlist ID for series
     * @param  bool  $allVodPlaylists  Process all VOD from all user playlists
     * @param  bool  $allSeriesPlaylists  Process all series from all user playlists
     * @param  bool  $overwriteExisting  Whether to overwrite existing IDs
     * @param  User|null  $user  The user to notify upon completion
     */
    public function __construct(
        public Collection|array|null $vodChannelIds = null,
        public Collection|array|null $seriesIds = null,
        public ?int $vodPlaylistId = null,
        public ?int $seriesPlaylistId = null,
        public bool $allVodPlaylists = false,
        public bool $allSeriesPlaylists = false,
        public bool $overwriteExisting = false,
        public ?User $user = null,
    ) {
        // Legacy support: convert Collections to arrays
        if ($this->vodChannelIds instanceof Collection) {
            $this->vodChannelIds = $this->vodChannelIds->toArray();
        }
        if ($this->seriesIds instanceof Collection) {
            $this->seriesIds = $this->seriesIds->toArray();
        }
    }

    /**
     * Execute the job.
     */
    public function handle(TmdbService $tmdb): void
    {
        Log::info('FetchTmdbIds: Starting job', [
            'vodPlaylistId' => $this->vodPlaylistId,
            'seriesPlaylistId' => $this->seriesPlaylistId,
            'allVodPlaylists' => $this->allVodPlaylists,
            'allSeriesPlaylists' => $this->allSeriesPlaylists,
            'user_id' => $this->user?->id,
            'overwriteExisting' => $this->overwriteExisting,
        ]);

        if (! $tmdb->isConfigured()) {
            Log::warning('FetchTmdbIds: TMDB API key not configured');
            $this->notifyUser('TMDB Lookup Failed', 'TMDB API key is not configured. Please add your API key in Settings.', 'danger');

            return;
        }

        // Process VOD channels (new playlist-based or legacy ID-based)
        if ($this->vodPlaylistId || $this->allVodPlaylists || ! empty($this->vodChannelIds)) {
            $this->processVodChannels($tmdb);
        }

        // Process Series (new playlist-based or legacy ID-based)
        if ($this->seriesPlaylistId || $this->allSeriesPlaylists || ! empty($this->seriesIds)) {
            $this->processSeries($tmdb);
        }

        // Send completion notification
        $this->sendCompletionNotification();
    }

    /**
     * Process VOD channels to fetch TMDB IDs.
     */
    protected function processVodChannels(TmdbService $tmdb): void
    {
        $query = Channel::where('is_vod', true);

        // Use playlist-based filtering if provided
        if ($this->vodPlaylistId) {
            $query->where('playlist_id', $this->vodPlaylistId)
                ->where('user_id', $this->user?->id)
                ->where('enabled', true);
        } elseif ($this->allVodPlaylists && $this->user) {
            $query->whereHas('playlist', function ($q) {
                $q->where('user_id', $this->user->id);
            })->where('enabled', true);
        } elseif (! empty($this->vodChannelIds)) {
            // Legacy: direct ID array support
            $query->whereIn('id', $this->vodChannelIds)
                ->where('user_id', $this->user?->id);
        } else {
            return; // No criteria specified
        }

        $count = $query->count();
        Log::info('FetchTmdbIds: Processing VOD channels', [
            'playlist_id' => $this->vodPlaylistId,
            'user_id' => $this->user?->id,
            'count' => $count,
        ]);

        // Use cursor for memory-efficient iteration
        foreach ($query->cursor() as $channel) {
            try {
                $this->processVodChannel($tmdb, $channel);
            } catch (\Exception $e) {
                $this->errorCount++;
                Log::error('FetchTmdbIds: Error processing VOD channel', [
                    'channel_id' => $channel->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Process a single VOD channel.
     */
    protected function processVodChannel(TmdbService $tmdb, Channel $channel): void
    {
        // Check if already has TMDB ID in the dedicated column
        if ($channel->tmdb_id && ! $this->overwriteExisting) {
            $this->skippedCount++;

            return;
        }

        // Check legacy info/movie_data fields for existing ID
        $legacyTmdbId = $channel->info['tmdb_id']
            ?? $channel->movie_data['tmdb_id']
            ?? null;

        // If legacy ID exists and we're not overwriting, migrate it to the column and skip
        if ($legacyTmdbId && ! $this->overwriteExisting) {
            $channel->update(['tmdb_id' => $legacyTmdbId]);
            $this->skippedCount++;

            return;
        }

        // Get title and year for search
        $title = $channel->title_custom ?? $channel->title ?? $channel->name;
        $year = $channel->year
            ?? $channel->info['year']
            ?? TmdbService::extractYearFromTitle($title);

        if (empty($title)) {
            $this->skippedCount++;

            return;
        }

        // Search TMDB
        $result = $tmdb->searchMovie($title, $year);

        if ($result && isset($result['tmdb_id'])) {
            // Update channel with found IDs - use dedicated columns
            $updateData = [
                'tmdb_id' => $result['tmdb_id'],
            ];

            if (! empty($result['imdb_id'])) {
                $updateData['imdb_id'] = $result['imdb_id'];
            }

            // Also update legacy info field for backward compatibility
            $info = $channel->info ?? [];
            $info['tmdb_id'] = $result['tmdb_id'];
            if (! empty($result['imdb_id'])) {
                $info['imdb_id'] = $result['imdb_id'];
            }

            // Fetch full movie details from TMDB to populate metadata
            $details = $tmdb->getMovieDetails($result['tmdb_id']);
            if ($details) {
                // Populate cover image if not already set
                if (! empty($details['poster_url']) && empty($info['cover_big'])) {
                    $info['cover_big'] = $details['poster_url'];
                }

                // Populate plot/description if not already set
                if (! empty($details['overview']) && empty($info['plot'])) {
                    $info['plot'] = $details['overview'];
                }

                // Populate genre if not already set
                if (! empty($details['genres']) && empty($info['genre'])) {
                    $info['genre'] = $details['genres'];
                }

                // Populate release date if not already set
                if (! empty($details['release_date']) && empty($info['release_date'])) {
                    $info['release_date'] = $details['release_date'];
                }

                // Populate rating if not already set
                if (! empty($details['vote_average']) && empty($info['rating'])) {
                    $info['rating'] = $details['vote_average'];
                }

                // Populate backdrop path
                if (! empty($details['backdrop_url'])) {
                    $info['backdrop_path'] = [$details['backdrop_url']];
                }

                // Populate cast if available
                if (! empty($details['cast'])) {
                    $info['cast'] = is_array($details['cast']) ? implode(', ', $details['cast']) : $details['cast'];
                }

                // Populate director if available
                if (! empty($details['director'])) {
                    $info['director'] = is_array($details['director']) ? implode(', ', $details['director']) : $details['director'];
                }

                // Populate YouTube trailer if available
                if (! empty($details['youtube_trailer'])) {
                    $info['youtube_trailer'] = $details['youtube_trailer'];
                }
            }

            $updateData['info'] = $info;

            $channel->update($updateData);

            Log::info('FetchTmdbIds: Found TMDB ID for VOD channel', [
                'channel_id' => $channel->id,
                'title' => $title,
                'tmdb_id' => $result['tmdb_id'],
                'confidence' => $result['confidence'] ?? 'N/A',
            ]);

            $this->foundCount++;
        } else {
            Log::debug('FetchTmdbIds: No TMDB match found for VOD channel', [
                'channel_id' => $channel->id,
                'title' => $title,
                'year' => $year,
            ]);
            $this->notFoundCount++;
        }
    }

    /**
     * Process Series to fetch TMDB/TVDB IDs.
     */
    protected function processSeries(TmdbService $tmdb): void
    {
        $query = Series::query();

        // Use playlist-based filtering if provided
        if ($this->seriesPlaylistId) {
            $query->where('playlist_id', $this->seriesPlaylistId)
                ->where('user_id', $this->user?->id)
                ->where('enabled', true);
        } elseif ($this->allSeriesPlaylists && $this->user) {
            $query->where('user_id', $this->user->id)
                ->where('enabled', true);
        } elseif (! empty($this->seriesIds)) {
            // Legacy: direct ID array support
            $query->whereIn('id', $this->seriesIds)
                ->where('user_id', $this->user?->id);
        } else {
            return; // No criteria specified
        }

        $count = $query->count();
        Log::info('FetchTmdbIds: Processing series', [
            'playlist_id' => $this->seriesPlaylistId,
            'user_id' => $this->user?->id,
            'count' => $count,
        ]);

        // Use cursor for memory-efficient iteration
        foreach ($query->cursor() as $series) {
            try {
                $this->processSingleSeries($tmdb, $series);
            } catch (\Exception $e) {
                $this->errorCount++;
                Log::error('FetchTmdbIds: Error processing series', [
                    'series_id' => $series->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Process a single series.
     */
    protected function processSingleSeries(TmdbService $tmdb, Series $series): void
    {
        // Check if already has IDs and we're not overwriting
        // Check both the dedicated columns and the legacy metadata field
        $existingTvdbId = $series->tvdb_id ?? $series->metadata['tvdb_id'] ?? null;
        $existingTmdbId = $series->tmdb_id ?? $series->metadata['tmdb_id'] ?? null;

        if (($existingTvdbId || $existingTmdbId) && ! $this->overwriteExisting) {
            Log::debug('FetchTmdbIds: Skipping series (already has IDs)', [
                'series_id' => $series->id,
                'name' => $series->name,
                'existing_tmdb_id' => $existingTmdbId,
                'existing_tvdb_id' => $existingTvdbId,
                'overwrite_existing' => $this->overwriteExisting,
            ]);
            $this->skippedCount++;

            return;
        }

        // Get name and year for search
        $name = $series->name;
        $year = null;

        if ($series->release_date) {
            $year = (int) substr($series->release_date, 0, 4);
        }

        if (! $year) {
            $year = TmdbService::extractYearFromTitle($name);
        }

        if (empty($name)) {
            Log::debug('FetchTmdbIds: Skipping series (empty name)', [
                'series_id' => $series->id,
            ]);
            $this->skippedCount++;

            return;
        }

        // Log search attempt
        Log::info('FetchTmdbIds: Searching TMDB for series', [
            'series_id' => $series->id,
            'name' => $name,
            'year' => $year,
            'release_date' => $series->release_date,
        ]);

        // Search TMDB
        $result = $tmdb->searchTvSeries($name, $year);

        if ($result && isset($result['tmdb_id'])) {
            // Update series with found IDs - use dedicated columns
            $updateData = [
                'tmdb_id' => $result['tmdb_id'],
            ];

            if (! empty($result['tvdb_id'])) {
                $updateData['tvdb_id'] = $result['tvdb_id'];
            }
            if (! empty($result['imdb_id'])) {
                $updateData['imdb_id'] = $result['imdb_id'];
            }

            // Also update legacy metadata field for backward compatibility
            $metadata = $series->metadata ?? [];
            $metadata['tmdb_id'] = $result['tmdb_id'];
            if (! empty($result['tvdb_id'])) {
                $metadata['tvdb_id'] = $result['tvdb_id'];
            }
            if (! empty($result['imdb_id'])) {
                $metadata['imdb_id'] = $result['imdb_id'];
            }
            $updateData['metadata'] = $metadata;

            // Fetch full series details from TMDB to populate metadata
            $details = $tmdb->getTvSeriesDetails($result['tmdb_id']);
            if ($details) {
                // Populate cover image if not already set
                if (! empty($details['poster_url']) && empty($series->cover)) {
                    $updateData['cover'] = $details['poster_url'];
                }

                // Populate plot/description if not already set
                if (! empty($details['overview']) && empty($series->plot)) {
                    $updateData['plot'] = $details['overview'];
                }

                // Populate genre if not already set
                if (! empty($details['genres']) && empty($series->genre)) {
                    $updateData['genre'] = $details['genres'];
                }

                // Populate release date if not already set
                if (! empty($details['first_air_date']) && empty($series->release_date)) {
                    $updateData['release_date'] = $details['first_air_date'];
                }

                // Populate rating if not already set
                if (! empty($details['vote_average']) && empty($series->rating)) {
                    $updateData['rating'] = $details['vote_average'];
                }

                // Populate backdrop path
                if (! empty($details['backdrop_url'])) {
                    $updateData['backdrop_path'] = json_encode([$details['backdrop_url']]);
                }

                // Populate cast if available
                if (! empty($details['cast'])) {
                    $updateData['cast'] = is_array($details['cast']) ? implode(', ', $details['cast']) : $details['cast'];
                }

                // Populate director if available
                if (! empty($details['director'])) {
                    $updateData['director'] = is_array($details['director']) ? implode(', ', $details['director']) : $details['director'];
                }

                // Populate YouTube trailer if available
                if (! empty($details['youtube_trailer'])) {
                    $updateData['youtube_trailer'] = $details['youtube_trailer'];
                }
            }

            $series->update($updateData);

            Log::info('FetchTmdbIds: Successfully found and saved IDs for series', [
                'series_id' => $series->id,
                'name' => $name,
                'tmdb_id' => $result['tmdb_id'],
                'tvdb_id' => $result['tvdb_id'] ?? null,
                'imdb_id' => $result['imdb_id'] ?? null,
                'confidence' => $result['confidence'] ?? 'N/A',
                'matched_name' => $result['name'] ?? null,
            ]);

            // Fetch and populate episode data from TMDB
            $this->processSeriesEpisodes($tmdb, $series, $result['tmdb_id']);

            $this->foundCount++;
        } else {
            Log::warning('FetchTmdbIds: No TMDB match found for series', [
                'series_id' => $series->id,
                'name' => $name,
                'year' => $year,
                'release_date' => $series->release_date,
                'search_result' => $result,
            ]);
            $this->notFoundCount++;
        }
    }

    /**
     * Process Series episodes after fetching series TMDB data.
     */
    protected function processSeriesEpisodes(TmdbService $tmdb, Series $series, int $tmdbId): void
    {
        Log::info('FetchTmdbIds: Fetching episode data for series', [
            'series_id' => $series->id,
            'tmdb_id' => $tmdbId,
        ]);

        // Get all seasons from TMDB
        $seasons = $tmdb->getAllSeasons($tmdbId);

        if (empty($seasons)) {
            Log::debug('FetchTmdbIds: No seasons found for series', [
                'series_id' => $series->id,
                'tmdb_id' => $tmdbId,
            ]);

            return;
        }

        $episodeCount = 0;

        foreach ($seasons as $season) {
            $seasonNumber = $season['season_number'] ?? 0;

            // Skip specials (season 0) unless explicitly needed
            if ($seasonNumber === 0) {
                continue;
            }

            // Fetch detailed season data with episodes
            $seasonDetails = $tmdb->getSeasonDetails($tmdbId, $seasonNumber);

            if (! $seasonDetails || empty($seasonDetails['episodes'])) {
                continue;
            }

            $seasonRecord = $series->seasons()
                ->where('season_number', $seasonNumber)
                ->first();

            if ($seasonRecord && ! empty($seasonDetails['poster_path'])) {
                $coverUrl = 'https://image.tmdb.org/t/p/w500'.$seasonDetails['poster_path'];
                $coverBigUrl = 'https://image.tmdb.org/t/p/original'.$seasonDetails['poster_path'];

                $seasonUpdateData = [];

                if (empty($seasonRecord->cover) || $this->overwriteExisting) {
                    $seasonUpdateData['cover'] = $coverUrl;
                }

                if (empty($seasonRecord->cover_big) || $this->overwriteExisting) {
                    $seasonUpdateData['cover_big'] = $coverBigUrl;
                }

                if (! empty($seasonUpdateData)) {
                    $seasonRecord->update($seasonUpdateData);
                }
            }

            foreach ($seasonDetails['episodes'] as $episodeData) {
                $episodeNumber = $episodeData['episode_number'] ?? 0;

                // Find matching episode in database
                $episode = $series->episodes()
                    ->where('season', $seasonNumber)
                    ->where('episode_num', $episodeNumber)
                    ->first();

                if ($episode) {
                    // Build update data - only update if field is empty or we're overwriting
                    $updateData = [];

                    if (! empty($episodeData['id'])) {
                        $updateData['tmdb_id'] = $episodeData['id'];
                    }

                    if (! empty($episodeData['name']) && (empty($episode->title) || $this->overwriteExisting)) {
                        $updateData['title'] = $episodeData['name'];
                    }

                    if (! empty($episodeData['overview'])) {
                        $info = $episode->info ?? [];
                        if (empty($info['plot']) || $this->overwriteExisting) {
                            $info['plot'] = $episodeData['overview'];
                            $updateData['info'] = $info;
                        }
                    }

                    if (! empty($episodeData['still_path'])) {
                        // Use original size for better quality
                        $stillUrl = 'https://image.tmdb.org/t/p/original'.$episodeData['still_path'];

                        // Store in both the dedicated cover column and info array
                        if (empty($episode->cover) || $this->overwriteExisting) {
                            $updateData['cover'] = $stillUrl;
                        }

                        $info = $updateData['info'] ?? $episode->info ?? [];
                        $info['movie_image'] = $stillUrl;
                        $updateData['info'] = $info;
                    }

                    if (! empty($episodeData['air_date'])) {
                        $info = $updateData['info'] ?? $episode->info ?? [];
                        $info['releasedate'] = $episodeData['air_date'];
                        $updateData['info'] = $info;
                    }

                    if (! empty($episodeData['vote_average'])) {
                        $info = $updateData['info'] ?? $episode->info ?? [];
                        $info['rating'] = $episodeData['vote_average'];
                        $updateData['info'] = $info;
                    }

                    if (! empty($updateData)) {
                        $episode->update($updateData);
                        $episodeCount++;
                    }
                }
            }
        }

        Log::info('FetchTmdbIds: Completed episode data fetch for series', [
            'series_id' => $series->id,
            'tmdb_id' => $tmdbId,
            'episodes_updated' => $episodeCount,
        ]);
    }

    /**
     * Send completion notification to user.
     */
    protected function sendCompletionNotification(): void
    {
        $total = $this->foundCount + $this->notFoundCount + $this->skippedCount + $this->errorCount;

        $body = sprintf(
            'Found: %d | Not found: %d | Skipped (already had IDs): %d | Errors: %d',
            $this->foundCount,
            $this->notFoundCount,
            $this->skippedCount,
            $this->errorCount
        );

        $title = "TMDB ID Lookup Complete ({$total} processed)";

        $this->notifyUser($title, $body, $this->errorCount > 0 ? 'warning' : 'success');
    }

    /**
     * Notify the user.
     */
    protected function notifyUser(string $title, string $body, string $type = 'success'): void
    {
        if (! $this->user) {
            return;
        }

        $notification = Notification::make()
            ->title($title)
            ->body($body);

        match ($type) {
            'success' => $notification->success(),
            'warning' => $notification->warning(),
            'danger' => $notification->danger(),
            default => $notification->info(),
        };

        $notification
            ->broadcast($this->user)
            ->sendToDatabase($this->user);
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('FetchTmdbIds job failed', [
            'error' => $exception->getMessage(),
            'vod_playlist_id' => $this->vodPlaylistId,
            'series_playlist_id' => $this->seriesPlaylistId,
            'all_vod_playlists' => $this->allVodPlaylists,
            'all_series_playlists' => $this->allSeriesPlaylists,
        ]);

        $this->notifyUser(
            'TMDB ID Lookup Failed',
            'An error occurred while fetching TMDB IDs: '.$exception->getMessage(),
            'danger'
        );
    }
}
