<?php

namespace App\Jobs;

use App\Enums\PlaylistSourceType;
use App\Enums\Status;
use App\Events\SyncCompleted;
use App\Models\Category;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\Season;
use App\Models\Series;
use App\Services\EmbyService;
use Carbon\Carbon;
use Exception;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessEmbySeriesSync implements ShouldQueue
{
    use Queueable;

    public $tries = 1;
    public $timeout = 60 * 60; // 1 hour timeout

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Playlist $playlist,
        public string $libraryId,
        public string $libraryName,
        public bool $useDirectPath = false,
        public bool $autoEnable = true,
        public ?bool $importCategoriesFromGenres = null,
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $start = now();

        try {
            // Refresh playlist to get latest data
            $this->playlist->refresh();
            
            // Check if an Emby Series sync is already in progress
            $embyConfig = $this->playlist->emby_config ?? [];
            if (isset($embyConfig['series']['syncing']) && $embyConfig['series']['syncing'] === true) {
                Log::info('Emby Series sync already in progress, skipping', [
                    'playlist_id' => $this->playlist->id,
                ]);
                return;
            }

            // Set the syncing flag
            $embyConfig['series']['syncing'] = true;
            
            // Update playlist status and set source type
            $this->playlist->update([
                'processing' => true,
                'status' => Status::Processing,
                'errors' => null,
                'progress' => 0,
                'source_type' => PlaylistSourceType::Emby,
                'emby_config' => [
                    'vod' => $this->playlist->emby_config['vod'] ?? null,
                    'series' => [
                        'library_id' => $this->libraryId,
                        'library_name' => $this->libraryName,
                        'use_direct_path' => $this->useDirectPath,
                        'auto_enable' => $this->autoEnable,
                        'import_categories_from_genres' => $this->importCategoriesFromGenres,
                        'syncing' => true,
                    ],
                ],
            ]);

            $embyService = new EmbyService();

            if (!$embyService->isConfigured()) {
                throw new Exception('Emby server is not configured. Please configure it in Settings.');
            }

            // Test connection
            $connectionTest = $embyService->testConnection();
            if (!$connectionTest['success']) {
                throw new Exception('Failed to connect to Emby server: ' . $connectionTest['error']);
            }

            $this->playlist->update(['progress' => 10]);

            // Fetch TV series from library
            Log::info('Fetching series from Emby', [
                'library_id' => $this->libraryId,
                'library_name' => $this->libraryName,
            ]);
            
            $seriesList = $embyService->getLibraryItems($this->libraryId, 'Series');
            
            Log::info('Emby series fetch result', [
                'count' => count($seriesList),
                'library_id' => $this->libraryId,
            ]);
            
            if (empty($seriesList)) {
                throw new Exception('No TV series found in the selected library. Library ID: ' . $this->libraryId);
            }

            $totalSeries = count($seriesList);
            $importedSeriesCount = 0;
            $importedEpisodeCount = 0;
            $batchNo = \Illuminate\Support\Str::orderedUuid()->toString();

            // Create category for this library
            $category = Category::firstOrCreate([
                'name_internal' => $this->libraryName,
                'playlist_id' => $this->playlist->id,
            ], [
                'name' => $this->libraryName,
                'user_id' => $this->playlist->user_id,
                'enabled' => true,
            ]);

            // Process each series
            foreach ($seriesList as $index => $seriesData) {
                try {
                    Log::debug('Processing series', [
                        'name' => $seriesData['Name'] ?? 'Unknown',
                        'id' => $seriesData['Id'] ?? 'Unknown',
                    ]);
                    $episodeCount = $this->processSeries($seriesData, $category, $batchNo, $embyService);
                    $importedSeriesCount++;
                    $importedEpisodeCount += $episodeCount;
                } catch (Exception $e) {
                    Log::warning("Failed to import series {$seriesData['Name']}: " . $e->getMessage(), [
                        'series_id' => $seriesData['Id'] ?? 'Unknown',
                        'exception' => $e,
                    ]);
                }

                // Update progress
                $progress = 10 + (int)(($index + 1) / $totalSeries * 80);
                $this->playlist->update(['progress' => $progress]);
            }

            // Clean up series, seasons, and episodes that no longer exist in Emby library
            $removedCounts = $this->cleanupOldSeries($batchNo);

            // Calculate completion time
            $completedIn = $start->diffInSeconds(now());
            $completedInRounded = round($completedIn, 2);

            // Clear the syncing flag
            $embyConfig = $this->playlist->fresh()->emby_config ?? [];
            $embyConfig['series']['syncing'] = false;

            // Update playlist status
            $this->playlist->update([
                'status' => Status::Completed,
                'synced' => now(),
                'errors' => null,
                'sync_time' => $completedIn,
                'progress' => 100,
                'processing' => false,
                'emby_config' => $embyConfig,
            ]);

            // Send success notification
            $removedMessage = $removedCounts['series'] > 0 
                ? " Removed {$removedCounts['series']} series ({$removedCounts['episodes']} episodes) no longer available on server." 
                : "";
            $message = "Successfully imported {$importedSeriesCount} series with {$importedEpisodeCount} episodes from Emby library '{$this->libraryName}' in {$completedInRounded} seconds.{$removedMessage}";
            Notification::make()
                ->success()
                ->title('Emby Series Sync Completed')
                ->body($message)
                ->broadcast($this->playlist->user);
            Notification::make()
                ->success()
                ->title('Emby Series Sync Completed')
                ->body($message)
                ->sendToDatabase($this->playlist->user);

            event(new SyncCompleted($this->playlist, 'emby_series'));
        } catch (Exception $e) {
            // Clear the syncing flag on error
            try {
                $embyConfig = $this->playlist->fresh()->emby_config ?? [];
                $embyConfig['series']['syncing'] = false;
                $this->playlist->update(['emby_config' => $embyConfig]);
            } catch (Exception $clearException) {
                Log::error('Failed to clear syncing flag after error', [
                    'playlist_id' => $this->playlist->id,
                    'error' => $clearException->getMessage(),
                ]);
            }
            
            $this->sendError('Emby Series sync failed', $e->getMessage());
        }
    }

    /**
     * Process a single series
     */
    private function processSeries(array $seriesData, Category $category, string $batchNo, EmbyService $embyService): int
    {
        $seriesName = $seriesData['Name'] ?? 'Unknown';
        $seriesId = $seriesData['Id'];
        $episodeCount = 0;

        // Get series metadata
        $overview = $seriesData['Overview'] ?? null;
        $year = $seriesData['ProductionYear'] ?? null;
        $genres = isset($seriesData['Genres']) ? implode(', ', $seriesData['Genres']) : null;
        $communityRating = $seriesData['CommunityRating'] ?? null;
        $officialRating = $seriesData['OfficialRating'] ?? null;
        $posterUrl = $embyService->getImageUrl($seriesId, 'Primary');
        $backdropUrl = $embyService->getImageUrl($seriesId, 'Backdrop');
        
        // Extract cast and director from People array
        $cast = null;
        $director = null;
        if (isset($seriesData['People']) && is_array($seriesData['People'])) {
            $cast = $embyService->extractCast($seriesData['People']);
            $director = $embyService->extractDirector($seriesData['People']);
        }
        
        // Calculate rating_5based from CommunityRating (rating / 2)
        $rating5based = $communityRating ? round($communityRating / 2, 1) : null;

        // Determine target categories - either genre-based or library-based
        $targetCategories = collect([$category]); // Default to library category
        
        if ($embyService->shouldCreateGroupsFromGenres($this->importCategoriesFromGenres)) {
            $genreCategories = $embyService->processItemGenres($seriesData, $this->playlist, $batchNo, 'category', $this->importCategoriesFromGenres);
            if ($genreCategories->isNotEmpty()) {
                $targetCategories = $genreCategories;
                Log::debug('Using genre-based categories for series', [
                    'series_name' => $seriesName,
                    'categories' => $genreCategories->pluck('name')->toArray(),
                ]);
            }
        }

        // Create or update series for each target category
        $allEpisodeCount = 0;
        foreach ($targetCategories as $targetCategory) {
            $seriesKey = $seriesName;
            if ($targetCategories->count() > 1) {
                // Add category suffix for multi-category content to avoid conflicts
                $seriesKey .= ' (' . $targetCategory->name . ')';
            }

            // Check for orphaned series with same emby_id but different source_series_id
            // This handles cases where configuration changes between syncs
            $orphanedSeries = Series::where('playlist_id', $this->playlist->id)
                ->where('name', $seriesKey)
                ->where('source_series_id', '!=', $seriesId)
                ->first();

            if ($orphanedSeries) {
                Log::info('Found orphaned series with different source_series_id', [
                    'series_id' => $orphanedSeries->id,
                    'name' => $seriesKey,
                    'old_source_series_id' => $orphanedSeries->source_series_id,
                    'new_source_series_id' => $seriesId,
                    'emby_id' => $seriesId,
                ]);
                
                // Update the source_series_id and batch number to match current sync
                $orphanedSeries->update([
                    'source_series_id' => $seriesId,
                    'import_batch_no' => $batchNo,
                ]);
                
                Log::info('Updated orphaned series to match current configuration', [
                    'series_id' => $orphanedSeries->id,
                    'updated_source_series_id' => $seriesId,
                ]);
            }

            // Create or update series
            $series = Series::updateOrCreate([
                'name' => $seriesKey,
                'playlist_id' => $this->playlist->id,
            ], [
                'user_id' => $this->playlist->user_id,
                'category_id' => $targetCategory->id,
                'source_series_id' => $seriesId, // Store Emby series ID for cleanup tracking
                'enabled' => $this->autoEnable,
                'cover' => $posterUrl,
                'plot' => $overview,
                'cast' => $cast,
                'director' => $director,
                'genre' => $genres,
                'release_date' => $year ? "{$year}-01-01" : null,
                'rating' => $rating5based,
                'backdrop_path' => [$backdropUrl],
                'import_batch_no' => $batchNo,
            ]);

            // Process seasons and episodes for this series instance
            $seriesEpisodeCount = $this->processSeriesSeasons($seriesData, $series, $batchNo, $embyService);
            $allEpisodeCount += $seriesEpisodeCount;
        }

        return $allEpisodeCount;
    }

    /**
     * Process seasons and episodes for a series
     */
    private function processSeriesSeasons(array $seriesData, Series $series, string $batchNo, EmbyService $embyService): int
    {
        $seriesName = $seriesData['Name'] ?? 'Unknown';
        $seriesId = $seriesData['Id'];
        $episodeCount = 0;

        // Get seasons for this series
        Log::debug('Fetching seasons for series', [
            'series_name' => $seriesName,
            'series_id' => $seriesId,
        ]);
        
        $seasons = $embyService->getSeasons($seriesId);
        
        Log::debug('Seasons fetched', [
            'series_name' => $seriesName,
            'season_count' => count($seasons),
        ]);

        foreach ($seasons as $seasonData) {
            $seasonNumber = $seasonData['IndexNumber'] ?? 0;
            $seasonId = $seasonData['Id'];

            // Create season
            $season = Season::firstOrCreate([
                'series_id' => $series->id,
                'season_number' => $seasonNumber,
            ], [
                'playlist_id' => $this->playlist->id,
                'user_id' => $this->playlist->user_id,
                'name' => $seasonData['Name'] ?? "Season {$seasonNumber}",
                'cover' => $embyService->getImageUrl($seasonId, 'Primary'),
                'import_batch_no' => $batchNo,
            ]);

            // Get episodes for this season
            Log::debug('Fetching episodes for season', [
                'series_name' => $seriesName,
                'season_number' => $seasonNumber,
                'season_id' => $seasonId,
            ]);
            
            $episodes = $embyService->getEpisodes($seriesId, $seasonId);
            
            Log::debug('Episodes fetched', [
                'series_name' => $seriesName,
                'season_number' => $seasonNumber,
                'episode_count' => count($episodes),
            ]);

            foreach ($episodes as $episodeData) {
                try {
                    $this->processEpisode($episodeData, $series, $season, $seasonNumber, $batchNo, $embyService);
                    $episodeCount++;
                } catch (Exception $e) {
                    Log::warning("Failed to import episode: " . $e->getMessage());
                }
            }
        }

        return $episodeCount;
    }

    /**
     * Process a single episode
     */
    private function processEpisode(array $episodeData, Series $series, Season $season, int $seasonNumber, string $batchNo, EmbyService $embyService): void
    {
        $episodeNumber = $episodeData['IndexNumber'] ?? 1;
        $episodeId = $episodeData['Id'];
        $title = $episodeData['Name'] ?? "Episode {$episodeNumber}";

        // Determine URL - use direct path or streaming URL
        if ($this->useDirectPath) {
            $url = $embyService->getFilePath($episodeData);
            if ($url) {
                $url = 'file://' . $url;
                Log::debug('Using direct file path for episode', ['title' => $title, 'path' => $url]);
            } else {
                $url = $embyService->getStreamUrl($episodeId);
                Log::debug('Direct path not available, using stream URL', ['title' => $title, 'url' => $url]);
            }
        } else {
            $url = $embyService->getStreamUrl($episodeId);
            Log::debug('Using stream URL for episode', ['title' => $title, 'url' => $url]);
        }

        // Get container extension
        $containerExtension = null;
        if (isset($episodeData['MediaSources'][0]['Container'])) {
            $containerExtension = $episodeData['MediaSources'][0]['Container'];
        }

        // Get episode metadata
        $overview = $episodeData['Overview'] ?? null;
        $posterUrl = $embyService->getImageUrl($episodeId, 'Primary');
        
        // Extract additional metadata for info JSON
        $releaseDate = null;
        if (isset($episodeData['PremiereDate'])) {
            try {
                $releaseDate = Carbon::parse($episodeData['PremiereDate'])->format('M d, Y');
            } catch (Exception $e) {
                Log::warning("Failed to parse PremiereDate for episode {$title}: " . $e->getMessage());
            }
        }
        
        // Convert RunTimeTicks to seconds (RunTimeTicks / 10000000)
        $durationSecs = null;
        $durationFormatted = null;
        if (isset($episodeData['RunTimeTicks'])) {
            $durationSecs = (int)($episodeData['RunTimeTicks'] / 10000000);
            $durationFormatted = $embyService->formatDuration($durationSecs);
        }
        
        // Get rating
        $rating = $episodeData['CommunityRating'] ?? null;
        
        // Build comprehensive info array matching Xtream format
        $info = [
            'emby_id' => $episodeId,
            'release_date' => $releaseDate,
            'duration_secs' => $durationSecs,
            'duration' => $durationFormatted,
            'rating' => $rating,
            'movie_image' => $posterUrl,
        ];

        // Create or update episode
        Log::debug('Creating/updating episode', [
            'title' => $title,
            'season_id' => $season->id,
            'episode_num' => $episodeNumber,
            'url' => $url,
            'info' => $info,
        ]);
        
        Episode::updateOrCreate([
            'season_id' => $season->id,
            'episode_num' => $episodeNumber,
        ], [
            'title' => $title,
            'url' => $url,
            'container_extension' => $containerExtension,
            'playlist_id' => $this->playlist->id,
            'user_id' => $this->playlist->user_id,
            'enabled' => $this->autoEnable,
            'series_id' => $series->id,
            'season' => $seasonNumber,
            'plot' => $overview,
            'cover' => $posterUrl,
            'import_batch_no' => $batchNo,
            'info' => $info,
        ]);
    }

    /**
     * Clean up series, seasons, and episodes that are no longer in the Emby library
     */
    private function cleanupOldSeries(string $currentBatchNo): array
    {
        // Only cleanup series that were imported from Emby (have source_series_id set)
        // and either have a different batch number OR null batch number (old imports)
        $seriesToDelete = Series::where('playlist_id', $this->playlist->id)
            ->whereNotNull('source_series_id') // Only Emby-sourced series
            ->where(function ($query) use ($currentBatchNo) {
                $query->where('import_batch_no', '!=', $currentBatchNo)
                      ->orWhereNull('import_batch_no');
            });

        $removedSeriesCount = $seriesToDelete->count();
        $removedEpisodeCount = 0;

        if ($removedSeriesCount > 0) {
            Log::info('Starting Emby Series cleanup', [
                'playlist_id' => $this->playlist->id,
                'current_batch' => $currentBatchNo,
                'series_to_remove' => $removedSeriesCount,
            ]);

            // Get the series BEFORE deleting to count episodes
            // Use direct where clause instead of whereHas for better performance
            $seriesToDeleteCollection = $seriesToDelete->get();
            foreach ($seriesToDeleteCollection as $series) {
                // Count episodes in this series (will be cascade deleted by database)
                $removedEpisodeCount += Episode::where('series_id', $series->id)->count();
            }

            // Rebuild the query and delete in one atomic operation
            // Database cascade deletes will automatically remove seasons and episodes
            $deletedSeries = Series::where('playlist_id', $this->playlist->id)
                ->whereNotNull('source_series_id') // Only Emby-sourced series
                ->where(function ($query) use ($currentBatchNo) {
                    $query->where('import_batch_no', '!=', $currentBatchNo)
                          ->orWhereNull('import_batch_no');
                })
                ->delete();

            Log::info('Emby Series cleanup completed', [
                'playlist_id' => $this->playlist->id,
                'series_removed' => $deletedSeries,
                'episodes_removed' => $removedEpisodeCount,
            ]);
        } else {
            Log::info('No series to cleanup', [
                'playlist_id' => $this->playlist->id,
                'current_batch' => $currentBatchNo,
            ]);
        }

        return [
            'series' => $removedSeriesCount,
            'episodes' => $removedEpisodeCount,
        ];
    }

    /**
     * Send error notification
     */
    private function sendError(string $message, string $error): void
    {
        Log::error("Error processing Emby Series sync for \"{$this->playlist->name}\": $error");

        Notification::make()
            ->danger()
            ->title("Error: {$message}")
            ->body('Please view your notifications for details.')
            ->broadcast($this->playlist->user);
        Notification::make()
            ->danger()
            ->title("Error: {$message}")
            ->body($error)
            ->sendToDatabase($this->playlist->user);

        $this->playlist->update([
            'status' => Status::Failed,
            'synced' => now(),
            'errors' => $error,
            'progress' => 100,
            'processing' => false,
        ]);

        event(new SyncCompleted($this->playlist, 'emby_series'));
    }
}