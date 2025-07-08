<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Job to manage stream buffering from FFmpeg process
 * 
 * This job reads data from FFmpeg stdout and stores it in Redis
 * for sharing among multiple clients (xTeVe-like functionality)
 */
class StreamBufferManager implements ShouldQueue
{
    use Queueable;

    public $timeout = 3600; // 1 hour
    public $tries = 1;

    private string $streamKey;
    private $stdout;
    private $stderr;
    private $process;

    const BUFFER_PREFIX = 'stream_buffer:';
    const SEGMENT_EXPIRY = 300; // 5 minutes

    public function __construct(string $streamKey, $stdout, $stderr, $process)
    {
        $this->streamKey = $streamKey;
        $this->stdout = $stdout;
        $this->stderr = $stderr;
        $this->process = $process;
    }

    /**
     * Execute the job
     */
    public function handle(): void
    {
        Log::channel('ffmpeg')->debug("StreamBufferManager: Starting buffer management for stream {$this->streamKey}");

        try {
            $this->runBufferLoop();
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("StreamBufferManager: Error in buffer loop for {$this->streamKey}: " . $e->getMessage());
            throw $e;
        } finally {
            $this->cleanup();
        }
    }

    /**
     * Run the main buffer loop
     */
    private function runBufferLoop(): void
    {
        $bufferKey = self::BUFFER_PREFIX . $this->streamKey;
        $segmentNumber = 0;
        $bufferSize = 188 * 1000; // ~188KB chunks (similar to xTeVe)

        // Make streams non-blocking
        stream_set_blocking($this->stdout, false);
        stream_set_blocking($this->stderr, false);

        $lastActivity = time();
        $maxInactiveTime = 60; // 60 seconds of no data before giving up

        while ($this->isStreamActive() && $this->isProcessRunning()) {
            $hasData = false;

            // Read data from FFmpeg stdout
            $data = fread($this->stdout, $bufferSize);
            if ($data !== false && strlen($data) > 0) {
                $segmentKey = "{$bufferKey}:segment_{$segmentNumber}";
                
                // Store segment in Redis with expiry
                Redis::setex($segmentKey, self::SEGMENT_EXPIRY, $data);
                
                // Update stream segment list
                Redis::lpush("{$bufferKey}:segments", $segmentNumber);
                Redis::ltrim("{$bufferKey}:segments", 0, 30); // Keep last 30 segments
                
                $segmentNumber++;
                $hasData = true;
                $lastActivity = time();
                
                // Update stream activity
                $this->updateStreamActivity();
                
                // Log progress for first few segments
                if ($segmentNumber <= 5 || $segmentNumber % 100 === 0) {
                    Log::channel('ffmpeg')->debug("Stream {$this->streamKey}: buffered segment {$segmentNumber} (" . strlen($data) . " bytes)");
                }
            }

            // Check for errors from FFmpeg stderr
            $error = fread($this->stderr, 1024);
            if ($error !== false && strlen($error) > 0) {
                Log::channel('ffmpeg')->error("Stream {$this->streamKey} FFmpeg error: {$error}");
            }

            // If no data for a while, check if we should continue
            if (!$hasData) {
                if (time() - $lastActivity > $maxInactiveTime) {
                    Log::channel('ffmpeg')->warning("Stream {$this->streamKey}: No data for {$maxInactiveTime}s, ending buffer manager");
                    break;
                }
                usleep(100000); // 100ms sleep when no data
            } else {
                usleep(10000); // 10ms sleep when actively buffering
            }
        }

        Log::channel('ffmpeg')->debug("StreamBufferManager: Buffer loop ended for {$this->streamKey} after {$segmentNumber} segments");
    }

    /**
     * Check if the stream is still active
     */
    private function isStreamActive(): bool
    {
        try {
            $streamInfo = Redis::get('shared_stream:' . $this->streamKey);
            if (!$streamInfo) {
                return false;
            }
            
            $data = json_decode($streamInfo, true);
            return isset($data['status']) && in_array($data['status'], ['starting', 'active']);
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->debug("Error checking stream status: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if the FFmpeg process is still running
     */
    private function isProcessRunning(): bool
    {
        if (!is_resource($this->process)) {
            return false;
        }

        $status = proc_get_status($this->process);
        return $status['running'] ?? false;
    }

    /**
     * Update stream activity timestamp
     */
    private function updateStreamActivity(): void
    {
        try {
            $streamKey = 'shared_stream:' . $this->streamKey;
            $streamInfo = Redis::get($streamKey);
            
            if ($streamInfo) {
                $data = json_decode($streamInfo, true);
                $data['last_activity'] = time();
                Redis::set($streamKey, json_encode($data));
            }
        } catch (\Exception $e) {
            // Don't fail the whole job for this
            Log::channel('ffmpeg')->debug("Failed to update stream activity: " . $e->getMessage());
        }
    }

    /**
     * Cleanup resources
     */
    private function cleanup(): void
    {
        try {
            // Close file handles
            if (is_resource($this->stdout)) {
                fclose($this->stdout);
            }
            if (is_resource($this->stderr)) {
                fclose($this->stderr);
            }
            
            // Close process
            if (is_resource($this->process)) {
                proc_close($this->process);
            }
            
            Log::channel('ffmpeg')->info("StreamBufferManager: Cleanup completed for {$this->streamKey}");
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("StreamBufferManager: Error during cleanup for {$this->streamKey}: " . $e->getMessage());
        }
    }
}
