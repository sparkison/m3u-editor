<?php

namespace App\Jobs;

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
            // Update playlist status
            $this->playlist->update([
                'processing' => true,
                'status' => Status::Processing,
                'errors' => null,
                'progress' => 0,
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
                    $episodeCount = $this->processSeries($seriesData, $category, $embyService);
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

            // Calculate completion time
            $completedIn = $start->diffInSeconds(now());
            $completedInRounded = round($completedIn, 2);

            // Update playlist status
            $this->playlist->update([
                'status' => Status::Completed,
                'synced' => now(),
                'errors' => null,
                'sync_time' => $completedIn,
                'progress' => 100,
                'processing' => false,
            ]);

            // Send success notification
            $message = "Successfully imported {$importedSeriesCount} series with {$importedEpisodeCount} episodes from Emby library '{$this->libraryName}' in {$completedInRounded} seconds.";
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

            event(new SyncCompleted($this->playlist));
        } catch (Exception $e) {
            $this->sendError('Emby Series sync failed', $e->getMessage());
        }
    }

    /**
     * Process a single series
     */
    private function processSeries(array $seriesData, Category $category, EmbyService $embyService): int
    {
        $seriesName = $seriesData['Name'] ?? 'Unknown';
        $seriesId = $seriesData['Id'];
        $episodeCount = 0;

        // Get series metadata
        $overview = $seriesData['Overview'] ?? null;
        $year = $seriesData['ProductionYear'] ?? null;
        $genres = isset($seriesData['Genres']) ? implode(', ', $seriesData['Genres']) : null;
        $rating = $seriesData['CommunityRating'] ?? null;
        $posterUrl = $embyService->getImageUrl($seriesId, 'Primary');
        $backdropUrl = $embyService->getImageUrl($seriesId, 'Backdrop');

        // Create or update series
        $series = Series::updateOrCreate([
            'name' => $seriesName,
            'playlist_id' => $this->playlist->id,
        ], [
            'user_id' => $this->playlist->user_id,
            'category_id' => $category->id,
            'enabled' => $this->autoEnable,
            'cover' => $posterUrl,
            'plot' => $overview,
            'cast' => null,
            'director' => null,
            'genre' => $genres,
            'release_date' => $year ? "{$year}-01-01" : null,
            'rating' => $rating,
            'backdrop_path' => [$backdropUrl],
        ]);

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
                    $this->processEpisode($episodeData, $season, $seasonNumber, $embyService);
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
    private function processEpisode(array $episodeData, Season $season, int $seasonNumber, EmbyService $embyService): void
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

        // Create or update episode
        Log::debug('Creating/updating episode', [
            'title' => $title,
            'season_id' => $season->id,
            'episode_num' => $episodeNumber,
            'url' => $url,
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
            'season' => $seasonNumber,
            'plot' => $overview,
            'cover' => $posterUrl,
            'info' => [
                'emby_id' => $episodeId,
            ],
        ]);
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

        event(new SyncCompleted($this->playlist));
    }
}