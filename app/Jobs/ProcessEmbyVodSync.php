<?php

namespace App\Jobs;

use App\Enums\PlaylistSourceType;
use App\Enums\Status;
use App\Events\SyncCompleted;
use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use App\Services\EmbyService;
use Carbon\Carbon;
use Exception;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProcessEmbyVodSync implements ShouldQueue
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
        public ?bool $importGroupsFromGenres = null,
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
            
            // Check if an Emby VOD sync is already in progress
            $embyConfig = $this->playlist->emby_config ?? [];
            if (isset($embyConfig['vod']['syncing']) && $embyConfig['vod']['syncing'] === true) {
                Log::info('Emby VOD sync already in progress, skipping', [
                    'playlist_id' => $this->playlist->id,
                ]);
                return;
            }

            // Set the syncing flag
            $embyConfig['vod']['syncing'] = true;
            
            // Update playlist status and set source type
            $this->playlist->update([
                'processing' => true,
                'status' => Status::Processing,
                'errors' => null,
                'progress' => 0,
                'source_type' => PlaylistSourceType::Emby,
                'emby_config' => [
                    'vod' => [
                        'library_id' => $this->libraryId,
                        'library_name' => $this->libraryName,
                        'use_direct_path' => $this->useDirectPath,
                        'auto_enable' => $this->autoEnable,
                        'import_groups_from_genres' => $this->importGroupsFromGenres,
                        'syncing' => true,
                    ],
                    'series' => $this->playlist->emby_config['series'] ?? null,
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

            // Fetch movies from library
            Log::info('Fetching movies from Emby library', [
                'library_id' => $this->libraryId,
                'library_name' => $this->libraryName,
            ]);
            
            $movies = $embyService->getLibraryItems($this->libraryId, 'Movie');
            
            Log::info('Emby movies fetch result', [
                'count' => count($movies),
                'library_id' => $this->libraryId,
                'library_name' => $this->libraryName,
            ]);
            
            if (empty($movies)) {
                throw new Exception('No movies found in the selected library. Library ID: ' . $this->libraryId);
            }

            $totalMovies = count($movies);
            $importedCount = 0;
            $batchNo = Str::orderedUuid()->toString();

            // Create or get group for this library
            $group = Group::firstOrCreate([
                'name_internal' => $this->libraryName,
                'playlist_id' => $this->playlist->id,
                'user_id' => $this->playlist->user_id,
                'custom' => false,
            ], [
                'name' => $this->libraryName,
                'import_batch_no' => $batchNo,
            ]);

            // Log all movie IDs and names for debugging
            $movieList = array_map(function($movie) {
                return [
                    'name' => $movie['Name'] ?? 'Unknown',
                    'id' => $movie['Id'] ?? 'Unknown',
                ];
            }, $movies);
            
            Log::info('All movies from Emby API', [
                'total_count' => count($movieList),
                'movies' => $movieList,
            ]);

            // Process each movie
            foreach ($movies as $index => $movie) {
                try {
                    Log::info('Processing movie from Emby', [
                        'name' => $movie['Name'] ?? 'Unknown',
                        'id' => $movie['Id'] ?? 'Unknown',
                        'index' => $index + 1,
                        'total' => $totalMovies,
                    ]);
                    $this->processMovie($movie, $group, $batchNo, $embyService);
                    $importedCount++;
                } catch (Exception $e) {
                    Log::warning("Failed to import movie {$movie['Name']}: " . $e->getMessage(), [
                        'movie_id' => $movie['Id'] ?? 'Unknown',
                        'exception' => $e,
                    ]);
                }

                // Update progress
                $progress = 10 + (int)(($index + 1) / $totalMovies * 80);
                $this->playlist->update(['progress' => $progress]);
            }

            // Clean up VOD channels that no longer exist in Emby library
            $removedCount = $this->cleanupOldVodChannels($batchNo);

            // Calculate completion time
            $completedIn = $start->diffInSeconds(now());
            $completedInRounded = round($completedIn, 2);

            // Clear the syncing flag
            $embyConfig = $this->playlist->fresh()->emby_config ?? [];
            $embyConfig['vod']['syncing'] = false;

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
            $removedMessage = $removedCount > 0 ? " Removed {$removedCount} movies no longer available on server." : "";
            $message = "Successfully imported {$importedCount} movies from Emby library '{$this->libraryName}' in {$completedInRounded} seconds.{$removedMessage}";
            Notification::make()
                ->success()
                ->title('Emby VOD Sync Completed')
                ->body($message)
                ->broadcast($this->playlist->user);
            Notification::make()
                ->success()
                ->title('Emby VOD Sync Completed')
                ->body($message)
                ->sendToDatabase($this->playlist->user);

            event(new SyncCompleted($this->playlist, 'emby_vod'));
        } catch (Exception $e) {
            // Clear the syncing flag on error
            try {
                $embyConfig = $this->playlist->fresh()->emby_config ?? [];
                $embyConfig['vod']['syncing'] = false;
                $this->playlist->update(['emby_config' => $embyConfig]);
            } catch (Exception $clearException) {
                Log::error('Failed to clear syncing flag after error', [
                    'playlist_id' => $this->playlist->id,
                    'error' => $clearException->getMessage(),
                ]);
            }
            
            $this->sendError('Emby VOD sync failed', $e->getMessage());
        }
    }

    /**
     * Process a single movie
     */
    private function processMovie(array $movie, Group $group, string $batchNo, EmbyService $embyService): void
    {
        $title = $movie['Name'] ?? 'Unknown';
        $embyId = $movie['Id'];

        // Determine URL - use direct path or streaming URL
        if ($this->useDirectPath) {
            $url = $embyService->getFilePath($movie);
            if ($url) {
                $url = 'file://' . $url;
                Log::debug('Using direct file path for movie', ['title' => $title, 'path' => $url]);
            } else {
                $url = $embyService->getStreamUrl($embyId);
                Log::debug('Direct path not available, using stream URL', ['title' => $title, 'url' => $url]);
            }
        } else {
            $url = $embyService->getStreamUrl($embyId);
            Log::debug('Using stream URL for movie', ['title' => $title, 'url' => $url]);
        }

        // Extract metadata
        $year = $movie['ProductionYear'] ?? null;
        $overview = $movie['Overview'] ?? null;
        $genres = isset($movie['Genres']) ? implode(', ', $movie['Genres']) : null;
        $rating = $movie['CommunityRating'] ?? null;
        $officialRating = $movie['OfficialRating'] ?? null;

        // Get poster image
        $posterUrl = $embyService->getImageUrl($embyId, 'Primary');
        $backdropUrl = $embyService->getImageUrl($embyId, 'Backdrop');

        // Get container extension
        $containerExtension = null;
        if (isset($movie['MediaSources'][0]['Container'])) {
            $containerExtension = $movie['MediaSources'][0]['Container'];
        }

        // Determine target groups - either genre-based or library-based
        $targetGroups = collect([$group]); // Default to library group
        
        Log::info('🔍 DEBUG: Processing movie genres', [
            'title' => $title,
            'emby_id' => $embyId,
            'raw_genres_from_api' => $movie['Genres'] ?? [],
            'importGroupsFromGenres_setting' => $this->importGroupsFromGenres,
        ]);
        
        if ($embyService->shouldCreateGroupsFromGenres($this->importGroupsFromGenres)) {
            $genreGroups = $embyService->processItemGenres($movie, $this->playlist, $batchNo, 'group', $this->importGroupsFromGenres);
            
            Log::info('🔍 DEBUG: Genre groups created', [
                'title' => $title,
                'genre_groups_count' => $genreGroups->count(),
                'genre_groups' => $genreGroups->pluck('name')->toArray(),
            ]);
            
            if ($genreGroups->isNotEmpty()) {
                $targetGroups = $genreGroups;
                Log::debug('Using genre-based groups for movie', [
                    'title' => $title,
                    'groups' => $genreGroups->pluck('name')->toArray(),
                ]);
            }
        }

        Log::info('🔍 DEBUG: Final target groups for movie', [
            'title' => $title,
            'target_groups_count' => $targetGroups->count(),
            'target_groups' => $targetGroups->pluck('name')->toArray(),
        ]);

        // Initialize sort index counter for tracking channel order
        static $sortIndex = 0;

        // Create or update VOD channel for each target group
        Log::info('🔍 DEBUG: Starting loop to create channels', [
            'title' => $title,
            'loop_iterations_expected' => $targetGroups->count(),
        ]);
        
        foreach ($targetGroups as $index => $targetGroup) {
            Log::info('🔍 DEBUG: Loop iteration', [
                'title' => $title,
                'iteration' => $index + 1,
                'of' => $targetGroups->count(),
                'current_group' => $targetGroup->name,
            ]);
            $sourceId = 'emby_' . $embyId;
            if ($targetGroups->count() > 1) {
                // Add group suffix for multi-group content to avoid conflicts
                $sourceId .= '_' . Str::slug($targetGroup->name_internal);
            }

            // Check if channel already exists with this source_id
            $existingChannel = Channel::where('source_id', $sourceId)
                ->where('playlist_id', $this->playlist->id)
                ->first();
            
            Log::info('VOD Channel sync attempt', [
                'title' => $title,
                'emby_id' => $embyId,
                'source_id' => $sourceId,
                'url' => $url,
                'group' => $targetGroup->name,
                'existing_channel_id' => $existingChannel?->id,
                'existing_batch_no' => $existingChannel?->import_batch_no,
                'new_batch_no' => $batchNo,
                'will_update' => $existingChannel !== null,
                'will_create' => $existingChannel === null,
            ]);
            
            Log::info('🔍 DEBUG: About to call updateOrCreate', [
                'title' => $title,
                'source_id' => $sourceId,
                'group' => $targetGroup->name,
            ]);
            
            $channel = Channel::updateOrCreate([
                'source_id' => $sourceId,
                'playlist_id' => $this->playlist->id,
            ], [
                'title' => $title,
                'name' => $title,
                'url' => $url,
                'group' => $targetGroup->name,
                'group_internal' => $targetGroup->name_internal,
                'group_id' => $targetGroup->id,
                'user_id' => $this->playlist->user_id,
                'enabled' => $this->autoEnable,
                'is_vod' => true,
                'logo_internal' => $posterUrl,
                'container_extension' => $containerExtension,
                'import_batch_no' => $batchNo,
                'sort' => $sortIndex++,
                'info' => [
                    'description' => $overview,
                    'year' => $year,
                    'genre' => $genres,
                    'rating' => $rating,
                    'official_rating' => $officialRating,
                    'backdrop' => $backdropUrl,
                    'emby_id' => $embyId,
                ],
            ]);
            
            Log::info('🔍 DEBUG: Channel created/updated', [
                'channel_id' => $channel->id,
                'title' => $title,
                'source_id' => $sourceId,
                'group' => $targetGroup->name,
                'was_created' => $channel->wasRecentlyCreated,
                'batch_no' => $channel->import_batch_no,
            ]);
            
            Log::info('VOD Channel synced successfully', [
                'channel_id' => $channel->id,
                'title' => $title,
                'source_id' => $sourceId,
                'was_created' => $channel->wasRecentlyCreated,
                'batch_no' => $channel->import_batch_no,
            ]);
        }
    }

    /**
     * Clean up VOD channels that no longer exist in the Emby library
     */
    private function cleanupOldVodChannels(string $batchNo): int
    {
        // Find VOD channels for this playlist that are:
        // 1. Have source_id starting with 'emby_' (to avoid deleting non-Emby content)
        // 2. Either have a different batch number OR have null batch number (old imports)
        $removedChannels = Channel::where('playlist_id', $this->playlist->id)
            ->where('is_vod', true)
            ->where('source_id', 'like', 'emby_%')
            ->where(function ($query) use ($batchNo) {
                $query->where('import_batch_no', '!=', $batchNo)
                      ->orWhereNull('import_batch_no');
            });

        $removedCount = $removedChannels->count();
        
        // Log all channels that will be removed for debugging
        $channelsToRemove = $removedChannels->get()->map(function ($ch) {
            return [
                'id' => $ch->id,
                'title' => $ch->title,
                'source_id' => $ch->source_id,
                'batch_no' => $ch->import_batch_no,
                'emby_id' => $ch->info['emby_id'] ?? 'unknown',
            ];
        })->toArray();

        if ($removedCount > 0) {
            Log::warning('Starting Emby VOD cleanup - ABOUT TO DELETE CHANNELS', [
                'playlist_id' => $this->playlist->id,
                'current_batch' => $batchNo,
                'channels_to_remove' => $removedCount,
                'channels_to_remove' => $channelsToRemove,
            ]);
            
            Log::warning("DELETING {$removedCount} VOD channels no longer in Emby library", [
                'playlist_id' => $this->playlist->id,
                'library_name' => $this->libraryName,
                'current_batch' => $batchNo,
                'channels' => $channelsToRemove,
            ]);

            // Rebuild the query and delete in one atomic operation
            Channel::where('playlist_id', $this->playlist->id)
                ->where('is_vod', true)
                ->where('source_id', 'like', 'emby_%')
                ->where(function ($query) use ($batchNo) {
                    $query->where('import_batch_no', '!=', $batchNo)
                          ->orWhereNull('import_batch_no');
                })
                ->delete();
            
            Log::info('Emby VOD cleanup completed', [
                'playlist_id' => $this->playlist->id,
                'channels_removed' => $removedCount,
            ]);
        } else {
            Log::info("No VOD channels to cleanup", [
                'playlist_id' => $this->playlist->id,
                'library_name' => $this->libraryName,
                'current_batch' => $batchNo,
            ]);
        }

        return $removedCount;
    }

    /**
     * Send error notification
     */
    private function sendError(string $message, string $error): void
    {
        Log::error("Error processing Emby VOD sync for \"{$this->playlist->name}\": $error");

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

        event(new SyncCompleted($this->playlist, 'emby_vod'));
    }
}