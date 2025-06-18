<?php

namespace App\Jobs;

use App\Services\SharedStreamService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Background job for starting shared streams
 * 
 * This job starts FFmpeg processes in the background to prevent HTTP timeouts
 * during stream initialization. The HTTP request returns immediately while
 * the stream starts up asynchronously.
 */
class StreamStarter implements ShouldQueue
{
    use Queueable;

    public $timeout = 60; // 1 minute
    public $tries = 1;

    private string $streamKey;
    private array $streamInfo;

    public function __construct(string $streamKey, array $streamInfo)
    {
        $this->streamKey = $streamKey;
        $this->streamInfo = $streamInfo;
    }

    /**
     * Execute the job.
     */
    public function handle(SharedStreamService $sharedStreamService): void
    {
        Log::channel('ffmpeg')->info("StreamStarter: Starting stream {$this->streamKey} in background");

        try {
            // Call the streaming process startup method directly
            $sharedStreamService->startStreamingProcessAsync($this->streamKey, $this->streamInfo);
            
            Log::channel('ffmpeg')->info("StreamStarter: Successfully started stream {$this->streamKey}");
            
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("StreamStarter: Failed to start stream {$this->streamKey}: " . $e->getMessage());
            
            // Update stream status to error
            $this->streamInfo['status'] = 'error';
            $this->streamInfo['error_message'] = $e->getMessage();
            $sharedStreamService->setStreamInfo($this->streamKey, $this->streamInfo);
            
            // Update database status
            \App\Models\SharedStream::where('stream_id', $this->streamKey)->update([
                'status' => 'error'
            ]);
            
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::channel('ffmpeg')->error("StreamStarter: Job failed for stream {$this->streamKey}: " . $exception->getMessage());
    }
}
