<?php

namespace App\Traits;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Trait for tracking active streams count across controllers and services
 * 
 * This trait provides consistent methods for incrementing and decrementing
 * the active streams count for playlists, ensuring proper count management
 * across all streaming controllers and services.
 */
trait TracksActiveStreams
{
    /**
     * Increment the active streams count for a playlist
     * 
     * @param int $playlistId
     * @return int The new active streams count
     */
    protected function incrementActiveStreams(int $playlistId): int
    {
        $activeStreamsKey = "active_streams:{$playlistId}";
        
        // Increment the counter
        $activeStreams = Redis::incr($activeStreamsKey);
        
        // Make sure we haven't gone negative for any reason
        if ($activeStreams <= 0) {
            Redis::set($activeStreamsKey, 1);
            $activeStreams = 1;
        }
        
        Log::channel('ffmpeg')->debug("Active streams for playlist {$playlistId}: {$activeStreams} (after increment)");
        
        return $activeStreams;
    }
    
    /**
     * Decrement the active streams count for a playlist
     * 
     * @param int $playlistId
     * @return int The new active streams count
     */
    protected function decrementActiveStreams(int $playlistId): int
    {
        $activeStreamsKey = "active_streams:{$playlistId}";
        
        // Decrement the counter
        $activeStreams = Redis::decr($activeStreamsKey);
        
        // Make sure we don't go below 0
        if ($activeStreams < 0) {
            Redis::set($activeStreamsKey, 0);
            $activeStreams = 0;
        }
        
        Log::channel('ffmpeg')->debug("Active streams for playlist {$playlistId}: {$activeStreams} (after decrement)");
        
        return $activeStreams;
    }
    
    /**
     * Get the current active streams count for a playlist
     * 
     * @param int $playlistId
     * @return int The current active streams count
     */
    protected function getActiveStreamsCount(int $playlistId): int
    {
        $activeStreamsKey = "active_streams:{$playlistId}";
        $count = (int) Redis::get($activeStreamsKey) ?? 0;
        
        // Ensure the count is never negative
        if ($count < 0) {
            Redis::set($activeStreamsKey, 0);
            $count = 0;
        }
        
        return $count;
    }
    
    /**
     * Check if adding a new stream would exceed the playlist's limit
     * 
     * @param int $playlistId
     * @param int $availableStreams The maximum allowed streams (0 = unlimited)
     * @param int $currentActiveStreams The current active streams count
     * @return bool True if limit would be exceeded
     */
    protected function wouldExceedStreamLimit(int $playlistId, int $availableStreams, int $currentActiveStreams): bool
    {
        if ($availableStreams <= 0) {
            return false; // Unlimited streams
        }
        
        return $currentActiveStreams >= $availableStreams;
    }
    
    /**
     * Reset the active streams count for a playlist to zero
     * 
     * @param int $playlistId
     * @return void
     */
    protected function resetActiveStreamsCount(int $playlistId): void
    {
        $activeStreamsKey = "active_streams:{$playlistId}";
        Redis::set($activeStreamsKey, 0);
        
        Log::channel('ffmpeg')->debug("Reset active streams count for playlist {$playlistId} to 0");
    }
    
    /**
     * Register a shutdown function to decrement active streams when the script ends
     * 
     * @param int $playlistId
     * @param string $logContext Additional context for logging
     * @return void
     */
    protected function registerStreamCleanupHandler(int $playlistId, string $logContext = ''): void
    {
        register_shutdown_function(function () use ($playlistId, $logContext) {
            $this->decrementActiveStreams($playlistId);
            
            if ($logContext) {
                Log::channel('ffmpeg')->debug("Stream cleanup executed for {$logContext}");
            }
        });
    }
}
