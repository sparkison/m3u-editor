<?php

namespace App\Traits;

use App\Events\StreamingStarted;
use App\Events\StreamingStopped;
use App\Models\SharedStream;
use Illuminate\Support\Facades\Log;

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
     * Decrement the active streams count for a playlist
     * 
     * @param string $uuid
     * @return int The new active streams count
     */
    protected function decrementActiveStreams(string $uuid)
    {
        // Fire event
        event(new StreamingStopped($uuid));

        $activeStreams = $this->getActiveStreamsCount($uuid);
        Log::channel('ffmpeg')->debug("Playlist {$uuid} active streams now: {$activeStreams} (after decrement; may be for failed/skipped attempt or confirmed stop)");
    }

    /**
     * Get the current active streams count for a playlist
     * 
     * @param string $uuid
     * @return int The current active streams count
     */
    protected function getActiveStreamsCount(string $uuid): int
    {
        return SharedStream::active()->where('stream_info->options->playlist_id', $uuid)->count();
    }

    /**
     * Check if adding a new stream would exceed the playlist's limit
     * 
     * @param string $uuid
     * @param int $availableStreams The maximum allowed streams (0 = unlimited)
     * @return bool True if limit would be exceeded
     */
    protected function wouldExceedStreamLimit(string $uuid, int $availableStreams): bool
    {
        if ($availableStreams <= 0 || $this->getActiveStreamsCount($uuid) < $availableStreams) {
            // Fire event
            event(new StreamingStarted($uuid));

            // Not exceeded
            return false;
        }

        // Would exceed limit
        return true;
    }
}
