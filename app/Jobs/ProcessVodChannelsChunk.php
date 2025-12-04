<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Playlist;
use App\Services\XtreamService;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessVodChannelsChunk implements ShouldQueue
{
    use Queueable;

    // Don't retry the job on failure
    public $tries = 1;

    // Giving a timeout of 10 minutes per chunk
    public $timeout = 60 * 10;

    // Default user agent
    public $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36';

    /**
     * Create a new job instance.
     */
    public function __construct(
        public array $channelIds,
        public int $playlistId,
        public int $chunkIndex,
        public int $totalChunks,
        public bool $force = false,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(XtreamService $xtream): void
    {
        $playlist = Playlist::find($this->playlistId);
        if (!$playlist) {
            Log::error('ProcessVodChannelsChunk: Playlist not found', ['playlist_id' => $this->playlistId]);
            return;
        }

        // Initialize Xtream service
        $xtream = $xtream->init(
            playlist: $playlist,
            retryLimit: 3
        );
        if (!$xtream) {
            Log::error('ProcessVodChannelsChunk: Xtream service initialization failed', ['playlist_id' => $playlist->id]);
            return;
        }

        // If this is the first chunk, notify user and reset progress
        if ($this->chunkIndex === 0) {
            Notification::make()
                ->info()
                ->title('Processing VOD Metadata')
                ->body('Fetching VOD metadata now. This may take a while depending on the number of VOD channels.')
                ->broadcast($playlist->user)
                ->sendToDatabase($playlist->user);
            
            $playlist->update([
                'vod_progress' => 0,
            ]);
        }

        // Get the channels for this chunk
        $channels = Channel::whereIn('id', $this->channelIds)->get(['id', 'name', 'source_id']);
        
        $processedCount = 0;
        $errorCount = 0;

        foreach ($channels as $channel) {
            try {
                $channel->fetchMetadata($xtream);
                $processedCount++;
            } catch (\Exception $e) {
                $errorCount++;
                Log::warning('ProcessVodChannelsChunk: Failed to fetch metadata for channel', [
                    'channel_id' => $channel->id,
                    'channel_name' => $channel->name,
                    'error' => $e->getMessage(),
                ]);
                // Continue processing other channels instead of stopping
            }
            
            // Small delay to avoid overwhelming the API
            usleep(50000); // 50ms delay
        }

        // Update progress based on chunks completed
        $progress = min(99, (($this->chunkIndex + 1) / $this->totalChunks) * 100);
        $playlist->update(['vod_progress' => round($progress)]);

        Log::debug('ProcessVodChannelsChunk completed', [
            'playlist_id' => $playlist->id,
            'chunk' => $this->chunkIndex + 1,
            'total_chunks' => $this->totalChunks,
            'processed' => $processedCount,
            'errors' => $errorCount,
            'progress' => $progress,
        ]);
    }
}
