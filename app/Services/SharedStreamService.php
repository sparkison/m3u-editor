<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Episode;
use App\Models\SharedStream;
use App\Models\SharedStreamClient;
use App\Traits\TracksActiveStreams;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process as SymfonyProcess;

/**
 * Shared Stream Service - Replicates xTeVe's streaming architecture
 * 
 * This service implements xTeVe-like functionality where multiple clients
 * can share the same upstream stream, reducing load on source servers.
 */
class SharedStreamService
{
    use TracksActiveStreams;

    const CACHE_PREFIX = 'shared_stream:';
    const CLIENT_PREFIX = 'stream_clients:';
    const BUFFER_PREFIX = 'stream_buffer:';
    const STREAM_PREFIX = 'shared_stream:'; // For compatibility with existing code
    const SEGMENT_EXPIRY = 300; // 5 minutes
    // const CLIENT_TIMEOUT = 120; // Now fetched from config

    private int $clientTimeout;
    private array $activeProcesses = []; // Store active FFmpeg processes

    public function __construct()
    {
        $this->clientTimeout = (int) config('proxy.shared_streaming.clients.timeout', 120);
    }

    /**
     * Get Redis connection instance
     */
    private function redis()
    {
        return app('redis');
    }

    /**
     * Get or create a shared stream for the given channel/episode
     * 
     * @param string $type 'channel' or 'episode'
     * @param int $modelId
     * @param string $streamUrl
     * @param string $title
     * @param string $format 'ts' or 'hls'
     * @param string $clientId Unique client identifier
     * @param array $options Additional streaming options
     * @return array Stream info with shared stream details
     */
    public function getOrCreateSharedStream(
        string $type,
        int $modelId,
        string $streamUrl,
        string $title,
        string $format,
        string $clientId,
        array $options = []
    ): array {
        $streamKey = $this->getStreamKey($type, $modelId, $streamUrl);
        $streamInfo = $this->getStreamInfo($streamKey);

        if (!$streamInfo || !$this->isStreamActive($streamKey)) {
            // Create new shared stream first
            $streamInfo = $this->createSharedStreamInternal(
                $streamKey,
                $type,
                $modelId,
                $streamUrl,
                $title,
                $format,
                $options
            );
            $isNewStream = true;
        } else {
            // Check if existing stream process is actually running
            $pid = $streamInfo['pid'] ?? null;
            $processRunning = $pid ? $this->isProcessRunning($pid) : false;
            
            if (!$processRunning && $streamInfo['status'] !== 'starting') {
                // Stream exists but process is dead, restart it
                Log::channel('ffmpeg')->info("Client {$clientId} found dead stream {$streamKey}, attempting restart");
                
                try {
                    // Requirement 5.1.b: Mark as 'starting' in Redis before attempting restart.
                    $streamInfo['status'] = 'starting';
                    $streamInfo['restart_attempt'] = time(); // Using existing field, can be last_restart_attempt_at
                    // Ensure other relevant fields like error_message are cleared if a restart is attempted.
                    unset($streamInfo['error_message']);
                    unset($streamInfo['ffmpeg_stderr']);
                    $this->setStreamInfo($streamKey, $streamInfo);
                    Log::channel('ffmpeg')->info("Stream {$streamKey}: Marked as 'starting' in Redis before attempting restart. Last attempt at: " . $streamInfo['restart_attempt']);
                    
                    // Restart the stream process
                    if ($streamInfo['format'] === 'hls') {
                        $this->startHLSStream($streamKey, $streamInfo);
                    } else {
                        $this->startDirectStream($streamKey, $streamInfo);
                    }
                    
                    // Update database
                    SharedStream::where('stream_id', $streamKey)->update([
                        'status' => 'starting',
                        'process_id' => $this->getProcessPid($streamKey), // getProcessPid fetches from Redis, which startHLS/Direct sets
                        'error_message' => null, // Clear previous errors on successful restart initiation
                        'last_client_activity' => now()
                    ]);
                    
                    // Requirement 5.1.c: Log PID update
                    $currentStreamInfoAfterRestart = $this->getStreamInfo($streamKey); // Fetch fresh info to get PID set by start methods
                    Log::channel('ffmpeg')->info("Stream {$streamKey}: Restart process initiated. New PID (from streamInfo): " . ($currentStreamInfoAfterRestart['pid'] ?? 'N/A') . ". DB process_id updated.");
                    Log::channel('ffmpeg')->info("Successfully restarted dead stream {$streamKey} for client {$clientId}");
                    $isNewStream = false; // This is a restart, not a new stream
                    
                } catch (\Exception $e) {
                    $restartErrorMessage = "Failed to restart dead stream {$streamKey}: " . $e->getMessage();
                    Log::channel('ffmpeg')->error($restartErrorMessage);

                    // Requirement 5.1.d: Set status to 'error' in Redis and DB on restart failure
                    $streamInfo['status'] = 'error';
                    $streamInfo['error_message'] = "Restart failed: " . $e->getMessage();
                    $this->setStreamInfo($streamKey, $streamInfo); // Update Redis

                    SharedStream::where('stream_id', $streamKey)->update([
                        'status' => 'error',
                        'error_message' => $streamInfo['error_message']
                    ]);
                    Log::channel('ffmpeg')->error("Stream {$streamKey}: FAILED to restart process. Status set to 'error'. Error: " . $e->getMessage());

                    // If restart fails, fall back to creating a new stream
                    Log::channel('ffmpeg')->info("Stream {$streamKey}: Attempting to create a new stream as fallback after restart failure.");
                    $streamInfo = $this->createSharedStreamInternal(
                        $streamKey,
                        $type,
                        $modelId,
                        $streamUrl,
                        $title,
                        $format,
                        $options
                    );
                    $isNewStream = true;
                }
            } else {
                // Join existing active stream
                Log::channel('ffmpeg')->debug("Client {$clientId} joining existing active stream {$streamKey}");
                $this->incrementClientCount($streamKey);
                $isNewStream = false;
            }
        }

        // Register this client for the stream AFTER ensuring the stream exists
        $this->registerClient($streamKey, $clientId, $options);

        // Add metadata about whether this was a new stream
        $streamInfo['is_new_stream'] = $isNewStream;
        
        return $streamInfo;
    }

    /**
     * Create a new shared stream (internal method)
     */
    public function createSharedStreamInternal(
        string $streamKey,
        string $type,
        int $modelId,
        string $streamUrl,
        string $title,
        string $format,
        array $options
    ): array {
        $streamInfo = [
            'stream_key' => $streamKey,
            'type' => $type,
            'model_id' => $modelId,
            'stream_url' => $streamUrl,
            'title' => $title,
            'format' => $format,
            'status' => 'starting',
            'client_count' => 1,
            'created_at' => now()->timestamp,
            'last_client_activity' => now()->timestamp,
            'options' => $options
        ];

        // Store stream info in Redis
        $this->setStreamInfo($streamKey, $streamInfo);

        // Also create database record for persistent tracking
        SharedStream::firstOrCreate(
            ['stream_id' => $streamKey],
            [
                'source_url' => $streamUrl,
                'format' => $format,
                'status' => 'starting',
                'stream_info' => json_encode($streamInfo),
                'started_at' => now()
            ]
        );

        // Start the streaming process directly (temporary fix for queue issues)
        try {
            Log::channel('ffmpeg')->debug("Starting stream process inline for {$streamKey}");
            $this->startStreamingProcess($streamKey, $streamInfo);
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Failed to start stream process for {$streamKey}: " . $e->getMessage());
            throw $e;
        }

        Log::channel('ffmpeg')->info("Created new shared stream {$streamKey} for {$type} {$title}"); // Changed from debug to info

        return $streamInfo;
    }

    /**
     * Start the actual streaming process (FFmpeg)
     */
    private function startStreamingProcess(string $streamKey, array $streamInfo): void
    {
        $format = $streamInfo['format'];
        $streamUrl = $streamInfo['stream_url'];
        $title = $streamInfo['title'];

        try {
            if ($format === 'hls') {
                $this->startHLSStream($streamKey, $streamInfo);
            } else {
                $this->startDirectStream($streamKey, $streamInfo);
            }
            
            // Don't set status to 'active' here - let startInitialBuffering do it when data is actually received
            // Just update the process ID in the database
            SharedStream::where('stream_id', $streamKey)->update([
                'process_id' => $this->getProcessPid($streamKey)
            ]);
            
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Failed to start streaming process for {$streamKey}: " . $e->getMessage());
            
            // Update stream status to error
            $streamInfo['status'] = 'error';
            $this->setStreamInfo($streamKey, $streamInfo);
            
            // Update database status
            SharedStream::where('stream_id', $streamKey)->update(['status' => 'error']);
            
            throw $e;
        }
    }

    /**
     * Start the actual streaming process (FFmpeg) - Async version for background jobs
     */
    public function startStreamingProcessAsync(string $streamKey, array $streamInfo): void
    {
        $format = $streamInfo['format'];
        $streamUrl = $streamInfo['stream_url'];
        $title = $streamInfo['title'];

        try {
            Log::channel('ffmpeg')->info("Starting async streaming process for {$streamKey} ({$title})");
            
            if ($format === 'hls') {
                $this->startHLSStream($streamKey, $streamInfo);
            } else {
                $this->startDirectStream($streamKey, $streamInfo);
            }
            
            // Don't set status to 'active' here - let startInitialBuffering do it when data is actually received
            // Just update the process ID in the database
            SharedStream::where('stream_id', $streamKey)->update([
                'process_id' => $this->getProcessPid($streamKey)
            ]);
            
            Log::channel('ffmpeg')->info("Successfully started async streaming process for {$streamKey} - waiting for data");
            
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Failed to start async streaming process for {$streamKey}: " . $e->getMessage());
            
            // Update stream status to error
            $streamInfo['status'] = 'error';
            $streamInfo['error_message'] = $e->getMessage();
            $this->setStreamInfo($streamKey, $streamInfo);
            
            // Update database status
            SharedStream::where('stream_id', $streamKey)->update([
                'status' => 'error',
                'error_message' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }

    /**
     * Start HLS streaming with segment buffering
     */
    private function startHLSStream(string $streamKey, array $streamInfo): void
    {
        $storageDir = $this->getStreamStorageDir($streamKey);
        Storage::makeDirectory($storageDir);

        $settings = ProxyService::getStreamSettings();
        $ffmpegPath = $settings['ffmpeg_path'] ?? 'jellyfin-ffmpeg';
        $userAgent = $settings['ffmpeg_user_agent'] ?? 'VLC/3.0.21';

        // Build FFmpeg command for HLS output
        $cmd = $this->buildHLSCommand($ffmpegPath, $streamInfo, $storageDir, $userAgent);

        // Use proc_open approach like HlsStreamService for consistency
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        
        $pipes = [];
        $absoluteStorageDir = Storage::path($storageDir);
        $process = proc_open($cmd, $descriptors, $pipes, $absoluteStorageDir);

        if (!is_resource($process)) {
            throw new \Exception("Failed to launch FFmpeg for HLS stream {$streamKey}");
        }

        // Close stdin and stdout (we don't need them for HLS)
        fclose($pipes[0]);
        fclose($pipes[1]);

        // Make stderr non-blocking for error logging
        stream_set_blocking($pipes[2], false);

        // Get the PID and store it
        $status = proc_get_status($process);
        $pid = $status['pid'];
        $this->setStreamProcess($streamKey, $pid);

        // Store process info in stream data
        $streamInfo['pid'] = $pid;
        $this->setStreamInfo($streamKey, $streamInfo);

        // Set up error logging from stderr
        $this->setupErrorLogging($streamKey, $pipes[2], $process);

        Log::channel('ffmpeg')->info("Started HLS shared stream {$streamKey} with PID {$pid}");
        Log::channel('ffmpeg')->debug("HLS Command: {$cmd}");
    }

    /**
     * Start direct streaming with buffering (similar to MPTS)
     */
    private function startDirectStream(string $streamKey, array $streamInfo): void
    {
        $settings = ProxyService::getStreamSettings();
        $ffmpegPath = $settings['ffmpeg_path'] ?? 'jellyfin-ffmpeg';
        $userAgent = $settings['ffmpeg_user_agent'] ?? 'VLC/3.0.21';

        // Build FFmpeg command for direct output
        $cmd = $this->buildDirectCommand($ffmpegPath, $streamInfo, $userAgent);
        
        Log::channel('ffmpeg')->debug("Starting shared stream process for {$streamKey}: {$cmd}");

        // Use proc_open for direct streaming
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $pipes = [];
        $process = proc_open($cmd, $descriptors, $pipes);
        
        if (!is_resource($process)) {
            Log::channel('ffmpeg')->error("Failed to start process for {$streamKey}. Command: {$cmd}");
            throw new \Exception("Failed to start direct stream process for {$streamKey}");
        }

        // Close stdin (we don't write to FFmpeg)
        fclose($pipes[0]);

        // Get the PID and store it
        $status = proc_get_status($process);
        if (!$status || !isset($status['pid'])) {
            Log::channel('ffmpeg')->error("Failed to get process status for {$streamKey}");
            proc_close($process);
            throw new \Exception("Failed to get process PID for {$streamKey}");
        }
        
        $pid = $status['pid'];
        $this->setStreamProcess($streamKey, $pid);

        // Store process handles for direct access during streaming
        $this->activeProcesses[$streamKey] = [
            'process' => $process,
            'stdout' => $pipes[1],
            'stderr' => $pipes[2],
            'pid' => $pid
        ];

        // Store process info in stream data
        $streamInfo['pid'] = $pid;
        $this->setStreamInfo($streamKey, $streamInfo);

        // Requirement 4.b.a: Brief, non-blocking read from stderr for immediate errors
        stream_set_blocking($pipes[2], false); // Ensure stderr is non-blocking
        $initialError = fread($pipes[2], 4096); // Read up to 4KB
        if ($initialError !== false && !empty(trim($initialError))) {
            Log::channel('ffmpeg')->error("Stream {$streamKey} (PID {$pid}): Immediate FFmpeg stderr after proc_open: " . trim($initialError));
        }
        // Note: $pipes[1] (stdout) and $pipes[2] (stderr) will be set to non-blocking again
        // at the beginning of startContinuousBuffering if they weren't already.

        // Start continuous buffer management for direct streaming
        $this->startContinuousBuffering($streamKey, $pipes[1], $pipes[2], $process);

        Log::channel('ffmpeg')->info("Started direct shared stream {$streamKey} with PID {$pid}");
        Log::channel('ffmpeg')->debug("Direct Command: {$cmd}");
    }

    /**
     * Build FFmpeg command for direct streaming (simplified for compatibility)
     * Uses proven working approach from StreamController
     */
    private function buildDirectCommand(string $ffmpegPath, array $streamInfo, string $userAgent): string
    {
        $settings = ProxyService::getStreamSettings();
        $streamUrl = $streamInfo['stream_url'];
        
        // Build command using proven working approach from StreamController
        $cmd = escapeshellcmd($ffmpegPath) . ' ';
        $cmd .= '-hide_banner -loglevel error '; // Added -hide_banner and -loglevel error globally
        
        // Better error handling and more robust connection options
        $cmd .= '-err_detect ignore_err -ignore_unknown ';
        $cmd .= '-fflags +nobuffer+igndts -flags low_delay ';
        $cmd .= '-analyzeduration 1M -probesize 1M -max_delay 500000 ';
        
        // HTTP options (simplified to match working approach)
        $cmd .= "-user_agent " . escapeshellarg($userAgent) . " -referer " . escapeshellarg("MyComputer") . " ";
        $cmd .= '-multiple_requests 1 -reconnect_on_network_error 1 ';
        $cmd .= '-reconnect_on_http_error 5xx,4xx -reconnect_streamed 1 ';
        $cmd .= '-reconnect_delay_max 5 ';
        $cmd .= '-noautorotate ';
        
        // Input
        $cmd .= '-i ' . escapeshellarg($streamUrl) . ' ';
        
        // Output options - use copy by default for better compatibility and performance
        $videoCodec = $settings['ffmpeg_codec_video'] ?? 'copy';
        $audioCodec = $settings['ffmpeg_codec_audio'] ?? 'copy';
        
        // Ensure codecs are strings, not arrays
        if (is_array($videoCodec)) {
            $videoCodec = 'copy';
        }
        if (is_array($audioCodec)) {
            $audioCodec = 'copy';
        }
        
        // Use copy by default for shared streaming to reduce CPU load
        if ($videoCodec === 'copy' || empty($videoCodec)) {
            $videoCodec = 'copy';
        }
        if ($audioCodec === 'copy' || empty($audioCodec)) {
            $audioCodec = 'copy';
        }
        
        // Output format with better streaming options
        $cmd .= "-c:v {$videoCodec} -c:a {$audioCodec} ";
        $cmd .= "-avoid_negative_ts disabled -copyts -start_at_zero ";
        $cmd .= "-f mpegts pipe:1";
        
        Log::channel('ffmpeg')->debug("SharedStream: Built optimized direct command for reliable streaming");
        
        return $cmd;
    }

    /**
     * Setup error logging for FFmpeg stderr
     */
    private function setupErrorLogging(string $streamKey, $stderr, $process): void
    {
        // Make stderr non-blocking to prevent hanging
        stream_set_blocking($stderr, false);
        
        // Simple error monitoring without complex shutdown functions
        // The main buffer manager will handle stderr monitoring
        Log::channel('ffmpeg')->debug("Stream {$streamKey}: Error logging setup completed");
    }

    /**
     * Get process PID for a stream
     */
    private function getProcessPid(string $streamKey): ?int
    {
        $pidKey = "stream_pid:{$streamKey}";
        return $this->redis()->get($pidKey);
    }

    /**
     * Set process PID for a stream
     */
    private function setStreamProcess(string $streamKey, int $pid): void
    {
        $pidKey = "stream_pid:{$streamKey}";
        $this->redis()->set($pidKey, $pid);
        Log::channel('ffmpeg')->debug("Stream {$streamKey}: Stored process PID {$pid} in Redis");
    }

    /**
     * Start continuous buffering process (xTeVe-style optimized)
     */
    private function startContinuousBuffering(string $streamKey, $stdout, $stderr, $process): void
    {
        // Set streams to non-blocking mode immediately
        stream_set_blocking($stdout, false);
        stream_set_blocking($stderr, false);
        
        // Start immediate buffering to prevent FFmpeg from hanging
        $this->startInitialBuffering($streamKey, $stdout, $stderr, $process);
        
        Log::channel('ffmpeg')->debug("Stream {$streamKey}: Starting xTeVe-style buffer management");
        
        // Start buffer manager in a way that doesn't block the HTTP response
        // We'll use a simple approach: buffer a few more segments then return,
        // letting the stream continue via process cleanup
        $this->runShortInitialBuffering($streamKey, $stdout, $stderr, $process);
    }



    /**
     * Manage buffering for direct streams (simplified)
     */
    private function manageDirectStreamBuffer(string $streamKey, $stdout, $stderr, $process): void
    {
        // This method is now replaced by the simplified startContinuousBuffering
        $this->startContinuousBuffering($streamKey, $stdout, $stderr, $process);
    }

    /**
     * Run buffer manager in background (simplified)
     */
    private function runBufferManagerBackground(string $streamKey, $stdout, $stderr, $process): void
    {
        // Set streams to non-blocking immediately
        stream_set_blocking($stdout, false);
        stream_set_blocking($stderr, false);
        
        // DON'T use register_shutdown_function here - it causes premature cleanup
        // This was causing race conditions where cleanup happens immediately
        
        // Start continuous buffering immediately in current process
        // This prevents the broken pipe issue by keeping the parent process active
        $this->runContinuousBuffering($streamKey, $stdout, $stderr, $process);
        
        Log::channel('ffmpeg')->debug("Stream {$streamKey}: Buffer manager running in current process");
    }

    /**
     * Start immediate buffering to prevent FFmpeg from hanging (simplified version)
     */
    private function startImmediateBuffering(string $streamKey, $stdout, $stderr, $process): void
    {
        // This is just a rename of the existing method for clarity
        $this->startInitialBuffering($streamKey, $stdout, $stderr, $process);
    }

    /**
     * Run buffer manager directly in current process (simplified fallback)
     */
    private function runDirectBufferManager(string $streamKey, $stdout, $stderr, $process): void
    {
        // DON'T use register_shutdown_function here - it causes race conditions
        // where cleanup happens immediately after stream starts successfully
        
        Log::channel('ffmpeg')->debug("Stream {$streamKey}: Set up direct buffer management");
    }

    /**
     * Simple pipe drain on shutdown to prevent overflow
     */
    private function drainPipesOnShutdown(string $streamKey, $stdout, $stderr, $process): void
    {
        try {
            $bufferKey = self::BUFFER_PREFIX . $streamKey;
            $segmentNumber = 0;
            $maxReads = 20; // Limit reads to prevent hanging
            
            // Quickly drain stdout to prevent pipe overflow
            while ($maxReads-- > 0 && !feof($stdout)) {
                $data = fread($stdout, 188000); // ~188KB chunks
                if ($data === false || strlen($data) === 0) {
                    break;
                }
                
                // Store in Redis
                $segmentKey = "{$bufferKey}:segment_{$segmentNumber}";
                $this->redis()->setex($segmentKey, self::SEGMENT_EXPIRY, $data);
                $this->redis()->lpush("{$bufferKey}:segments", $segmentNumber);
                $this->redis()->ltrim("{$bufferKey}:segments", 0, 30);
                $segmentNumber++;
            }
            
            // Drain stderr for errors
            while (!feof($stderr)) {
                $error = fread($stderr, 1024);
                if ($error !== false && !empty(trim($error))) {
                    Log::channel('ffmpeg')->error("Stream {$streamKey}: " . trim($error));
                } else {
                    break;
                }
            }
            
            Log::channel('ffmpeg')->debug("Stream {$streamKey}: Shutdown drain completed, read {$segmentNumber} segments");
            
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Stream {$streamKey}: Error during shutdown drain: " . $e->getMessage());
        } finally {
            // Clean up resources
            if (is_resource($stdout)) fclose($stdout);
            if (is_resource($stderr)) fclose($stderr);
            if (is_resource($process)) proc_close($process);
        }
    }

    /**
     * Start initial buffering to prevent FFmpeg from hanging
     * Optimized for faster startup and better VLC compatibility
     */
    private function startInitialBuffering(string $streamKey, $stdout, $stderr, $process): void
    {
        $bufferKey = self::BUFFER_PREFIX . $streamKey;
        $segmentNumber = 0;
        
        // Optimized settings for faster startup
        $targetSegmentSize = 188 * 1000; // 188KB segments (xTeVe compatible)
        $readChunkSize = 32768; // 32KB reads for better performance
        $maxWait = 10; // Reduced from 15 to 10 seconds for faster startup
        $waitTime = 0;

        Log::channel('ffmpeg')->info("Stream {$streamKey}: Starting initial buffering. Waiting up to {$maxWait}s for FFmpeg data.");

        // Wait for FFmpeg to start producing data and build initial buffer
        $accumulatedData = '';
        $accumulatedSize = 0;
        $hasFirstData = false;
        $redis = $this->redis();

        while ($waitTime < $maxWait) {
            // Use stream_select to properly handle non-blocking I/O
            $read = [$stdout];
            $write = null;
            $except = null;
            
            // Wait up to 100ms for data to be available
            $result = stream_select($read, $write, $except, 0, 100000);
            
            if ($result > 0 && in_array($stdout, $read)) {
                // Data is available, try to read it
                $chunk = fread($stdout, $readChunkSize);
                if ($chunk !== false && strlen($chunk) > 0) {
                    if (!$hasFirstData) {
                        Log::channel('ffmpeg')->info("Stream {$streamKey}: First data chunk received from FFmpeg (" . strlen($chunk) . " bytes).");
                        $hasFirstData = true;
                    }
                    
                    $accumulatedData .= $chunk;
                    $accumulatedSize += strlen($chunk);
                    $this->updateStreamActivity($streamKey);
                    
                    // Create segments more aggressively for faster startup
                    if ($accumulatedSize >= $targetSegmentSize || 
                        ($accumulatedSize >= 64000 && $segmentNumber < 2)) { // Smaller initial segments for faster startup
                        
                        // Use direct Redis calls for initial buffering (faster than pipeline for small operations)
                        $segmentKey = "{$bufferKey}:segment_{$segmentNumber}";
                        $redis->setex($segmentKey, self::SEGMENT_EXPIRY, $accumulatedData);
                        $redis->lpush("{$bufferKey}:segments", $segmentNumber);
                        $redis->ltrim("{$bufferKey}:segments", 0, 100);
                        
                        Log::channel('ffmpeg')->debug("Stream {$streamKey}: Initial buffer segment {$segmentNumber} buffered ({$accumulatedSize} bytes)");
                        
                        // Requirement 1.a: Update status to 'active' after first segment
                        if ($segmentNumber == 0 // This means it's the first segment successfully processed (about to be incremented)
                        )
                        {
                            $streamInfo = $this->getStreamInfo($streamKey);
                            if ($streamInfo) {
                                $streamInfo['status'] = 'active';
                                $streamInfo['first_data_at'] = time(); // Keep this for consistency
                                $this->setStreamInfo($streamKey, $streamInfo);

                                SharedStream::where('stream_id', $streamKey)->update([
                                    'status' => 'active'
                                ]);
                                Log::channel('ffmpeg')->info("Stream {$streamKey}: Initial segment " . ($segmentNumber + 1) . " buffered. Marking stream as ACTIVE.");
                            }
                        }

                        $segmentNumber++;
                        $accumulatedData = '';
                        $accumulatedSize = 0;
                        
                        // Build up fewer initial segments for faster startup
                        if ($segmentNumber >= 1) { // Reduced from 2 to 1 for even faster startup
                            break;
                        }
                    }
                }
            } else {
                // No immediate data available, check for errors
                $error = fread($stderr, 1024);
                if ($error !== false && strlen($error) > 0) {
                    Log::channel('ffmpeg')->error("Stream {$streamKey} FFmpeg error during initial buffering: {$error}");
                }
                
                // Increment wait time only when no data is available
                $waitTime += 0.1; // 100ms increment
            }
        }
        
        // Flush any remaining data from initial buffering
        if ($accumulatedSize > 0) {
            $segmentKey = "{$bufferKey}:segment_{$segmentNumber}";
            $redis->setex($segmentKey, self::SEGMENT_EXPIRY, $accumulatedData);
            $redis->lpush("{$bufferKey}:segments", $segmentNumber);
            $redis->ltrim("{$bufferKey}:segments", 0, 100);
            
            Log::channel('ffmpeg')->debug("Stream {$streamKey}: Final initial buffer segment {$segmentNumber} buffered ({$accumulatedSize} bytes)");
            $segmentNumber++;
        }
        
        if ($segmentNumber > 0) {
            Log::channel('ffmpeg')->info("Stream {$streamKey}: Initial buffering completed successfully with {$segmentNumber} segments");
            
            // Ensure status is active if segments were buffered (it might have been set already by the first segment logic)
            $streamInfo = $this->getStreamInfo($streamKey);
            if ($streamInfo && $streamInfo['status'] !== 'active') {
                $streamInfo['status'] = 'active';
                if (!isset($streamInfo['first_data_at'])) {
                    $streamInfo['first_data_at'] = time();
                }
                $streamInfo['initial_segments'] = $segmentNumber;
                $this->setStreamInfo($streamKey, $streamInfo);
                
                SharedStream::where('stream_id', $streamKey)->update(['status' => 'active']);
                Log::channel('ffmpeg')->info("Stream {$streamKey}: Confirmed status as 'active' after initial buffering.");
            }
        } else {
            // Requirement 1.c: No data was buffered ($segmentNumber is still 0)
            Log::channel('ffmpeg')->error("Stream {$streamKey}: FAILED to receive initial data from FFmpeg after {$maxWait}s.");

            $error_output = 'No specific error output from FFmpeg stderr.';
            // Attempt to read from stderr
            $stderr_content = '';
            while (($line = fgets($stderr)) !== false) {
                $stderr_content .= $line;
            }
            if (!empty(trim($stderr_content))) {
                $error_output = trim($stderr_content);
                Log::channel('ffmpeg')->error("Stream {$streamKey}: FFmpeg stderr output: {$error_output}");
            } else {
                Log::channel('ffmpeg')->info("Stream {$streamKey}: No FFmpeg stderr output detected.");
            }

            $streamInfo = $this->getStreamInfo($streamKey);
            if ($streamInfo) {
                $errorMessage = 'FFmpeg failed to produce initial data after ' . $maxWait . 's.';
                $streamInfo['status'] = 'error';
                $streamInfo['error_message'] = $errorMessage;
                $streamInfo['ffmpeg_stderr'] = $error_output; // Store stderr output
                $this->setStreamInfo($streamKey, $streamInfo);
                
                SharedStream::where('stream_id', $streamKey)->update([
                    'status' => 'error',
                    'error_message' => $errorMessage,
                    'stream_info->ffmpeg_stderr' => $error_output // Store in DB as well
                ]);
                Log::channel('ffmpeg')->info("Stream {$streamKey}: Status updated to 'error' due to no initial data. FFmpeg stderr logged.");
            }
        }
    }

    /**
     * Run short initial buffering to build up a small buffer without blocking HTTP response
     */
    private function runShortInitialBuffering(string $streamKey, $stdout, $stderr, $process): void
    {
        $bufferKey = self::BUFFER_PREFIX . $streamKey;
        $targetSegments = 5; // Buffer 5 segments then return
        $segmentNumber = 1; // Start from 1 since initial buffering already created segment 0
        $accumulatedData = '';
        $accumulatedSize = 0;
        $targetChunkSize = 188 * 1000; // 188KB chunks
        $redis = $this->redis();
        $maxWaitTime = 3; // Maximum 3 seconds for this initial buffering
        $startTime = time();
        
        Log::channel('ffmpeg')->debug("Stream {$streamKey}: Starting short initial buffering for {$targetSegments} segments");
        
        while ($segmentNumber < $targetSegments && (time() - $startTime) < $maxWaitTime) {
            // Quick non-blocking read
            $chunk = fread($stdout, 32768);
            if ($chunk !== false && strlen($chunk) > 0) {
                $accumulatedData .= $chunk;
                $accumulatedSize += strlen($chunk);
                
                // Flush when we have enough data
                if ($accumulatedSize >= $targetChunkSize) {
                    $segmentKey = "{$bufferKey}:segment_{$segmentNumber}";
                    $redis->setex($segmentKey, self::SEGMENT_EXPIRY, $accumulatedData);
                    $redis->lpush("{$bufferKey}:segments", $segmentNumber);
                    $redis->ltrim("{$bufferKey}:segments", 0, 50);
                    
                    Log::channel('ffmpeg')->debug("Stream {$streamKey}: Buffered initial segment {$segmentNumber}");
                    
                    $segmentNumber++;
                    $accumulatedData = '';
                    $accumulatedSize = 0;
                    
                    $this->updateStreamActivity($streamKey);
                }
            } else {
                // No data available, small sleep
                usleep(10000); // 10ms
            }
            
            // Check for errors
            $error = fread($stderr, 1024);
            if ($error !== false && strlen($error) > 0) {
                Log::channel('ffmpeg')->error("Stream {$streamKey}: {$error}");
            }
        }
        
        // Flush any remaining data
        if ($accumulatedSize > 0) {
            $segmentKey = "{$bufferKey}:segment_{$segmentNumber}";
            $redis->setex($segmentKey, self::SEGMENT_EXPIRY, $accumulatedData);
            $redis->lpush("{$bufferKey}:segments", $segmentNumber);
            $segmentNumber++;
            Log::channel('ffmpeg')->debug("Stream {$streamKey}: Flushed remaining data as segment {$segmentNumber}");
        }
        
        Log::channel('ffmpeg')->info("Stream {$streamKey}: Short initial buffering completed with {$segmentNumber} total segments");
        
        // Initial buffering is complete. The process handles are stored for on-demand reading.
        // Clients will read additional data via getNextStreamSegments() which can read directly from FFmpeg.
    }

    /**
     * Start continuous buffering in the background
     */
    private function startContinuousBufferJob(string $streamKey, $stdout, $stderr, $process): void
    {
        // Use a simpler approach: just continue buffering in current process
        // without forking to avoid file descriptor issues
        
        Log::channel('ffmpeg')->info("Stream {$streamKey}: Starting continuous buffering in current process");
        
        // Start continuous buffering without forking
        // This will run in the background naturally as the process continues
        $this->runContinuousBuffering($streamKey, $stdout, $stderr, $process);
    }

    /**
     * Run continuous buffering for the stream
     */
    private function runContinuousBuffering(string $streamKey, $stdout, $stderr, $process): void
    {
        $bufferKey = self::BUFFER_PREFIX . $streamKey;
        $redis = $this->redis();
        $targetChunkSize = 188 * 1000; // 188KB chunks
        $accumulatedData = '';
        $accumulatedSize = 0;
        $maxIdleTime = 30; // Stop after 30 seconds of no data
        $lastDataTime = time();
        
        // Get current segment count to continue numbering
        $segmentNumber = $redis->llen("{$bufferKey}:segments");
        
        Log::channel('ffmpeg')->debug("Stream {$streamKey}: Continuous buffering started from segment {$segmentNumber}");
        
        while (true) {
            // Check if the process is still running
            $status = proc_get_status($process);
            if (!$status['running']) {
                Log::channel('ffmpeg')->info("Stream {$streamKey}: FFmpeg process ended, stopping continuous buffering");
                break;
            }
            
            // Check if stream should be stopped (no clients for too long)
            $streamInfo = $this->getStreamInfo($streamKey);
            if (!$streamInfo || $streamInfo['status'] === 'stopped') {
                Log::channel('ffmpeg')->info("Stream {$streamKey}: Stream marked for stop, ending continuous buffering");
                break;
            }
            
            // Try to read data
            $chunk = fread($stdout, 32768);
            if ($chunk !== false && strlen($chunk) > 0) {
                $accumulatedData .= $chunk;
                $accumulatedSize += strlen($chunk);
                $lastDataTime = time();
                
                // Create segment when we have enough data
                if ($accumulatedSize >= $targetChunkSize) {
                    $segmentKey = "{$bufferKey}:segment_{$segmentNumber}";
                    $redis->setex($segmentKey, self::SEGMENT_EXPIRY, $accumulatedData);
                    $redis->lpush("{$bufferKey}:segments", $segmentNumber);
                    
                    // Keep only recent segments (prevent memory bloat)
                    $redis->ltrim("{$bufferKey}:segments", 0, 50);
                    
                    // Clean up old segments
                    $oldSegmentNumber = $segmentNumber - 50;
                    if ($oldSegmentNumber >= 0) {
                        $redis->del("{$bufferKey}:segment_{$oldSegmentNumber}");
                    }
                    
                    Log::channel('ffmpeg')->debug("Stream {$streamKey}: Buffered continuous segment {$segmentNumber} ({$accumulatedSize} bytes)");
                    
                    $segmentNumber++;
                    $accumulatedData = '';
                    $accumulatedSize = 0;
                    
                    $this->updateStreamActivity($streamKey);
                }
            } else {
                // No data available
                if ((time() - $lastDataTime) > $maxIdleTime) {
                    Log::channel('ffmpeg')->warning("Stream {$streamKey}: No data for {$maxIdleTime}s, stopping continuous buffering");
                    break;
                }
                
                // Small sleep to prevent high CPU usage
                usleep(50000); // 50ms
            }
            
            // Check for FFmpeg errors
            $error = fread($stderr, 1024);
            if ($error !== false && strlen($error) > 0) {
                Log::channel('ffmpeg')->error("Stream {$streamKey}: {$error}");
            }
        }
        
        // Flush any remaining data
        if ($accumulatedSize > 0) {
            $segmentKey = "{$bufferKey}:segment_{$segmentNumber}";
            $redis->setex($segmentKey, self::SEGMENT_EXPIRY, $accumulatedData);
            $redis->lpush("{$bufferKey}:segments", $segmentNumber);
            Log::channel('ffmpeg')->debug("Stream {$streamKey}: Final continuous segment {$segmentNumber} flushed");
        }
        
        Log::channel('ffmpeg')->info("Stream {$streamKey}: Continuous buffering ended");
        
        // Close the process
        proc_close($process);
    }

    /**
     * Build HLS command for shared HLS streaming
     */
    private function buildHLSCommand(string $ffmpegPath, array $streamInfo, string $storageDir, string $userAgent): string
    {
        $settings = ProxyService::getStreamSettings();
        $streamUrl = $streamInfo['stream_url'];
        
        $cmd = escapeshellcmd($ffmpegPath) . ' ';
        $cmd .= '-hide_banner -loglevel error ';
        $cmd .= '-user_agent ' . escapeshellarg($userAgent) . ' ';

        // Add robust input options similar to buildDirectCommand
        $cmd .= '-err_detect ignore_err -ignore_unknown ';
        $cmd .= '-fflags +nobuffer+igndts -flags low_delay '; // Consider if +nobuffer is always needed for HLS
        $cmd .= '-multiple_requests 1 -reconnect_on_network_error 1 ';
        $cmd .= '-reconnect_on_http_error 5xx,4xx -reconnect_streamed 1 ';
        $cmd .= '-reconnect_delay_max 5 ';
        $cmd .= '-noautorotate ';

        $cmd .= '-i ' . escapeshellarg($streamUrl) . ' ';
        $cmd .= '-c copy -f hls ';
        $cmd .= '-hls_time 4 -hls_list_size 10 ';
        $cmd .= '-hls_flags delete_segments ';
        $cmd .= escapeshellarg($storageDir . '/playlist.m3u8');
        
        return $cmd;
    }

    /**
     * Generate stream key from type, model ID, and URL
     */
    private function getStreamKey(string $type, int $modelId, string $streamUrl): string
    {
        return self::CACHE_PREFIX . $type . ':' . $modelId . ':' . md5($streamUrl);
    }

    /**
     * Get stream info from Redis
     */
    private function getStreamInfo(string $streamKey): ?array
    {
        try {
            $data = $this->redis()->get($streamKey);
            if ($data === null || $data === false) {
                Log::channel('ffmpeg')->debug("Stream {$streamKey}: No stream info found in Redis");
                return null;
            }
            
            if (empty($data)) {
                Log::channel('ffmpeg')->warning("Stream {$streamKey}: Empty stream info data in Redis");
                return null;
            }
            
            $decoded = json_decode($data, true);
            if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                Log::channel('ffmpeg')->error("Stream {$streamKey}: Failed to decode stream info JSON: " . json_last_error_msg() . " (data: " . substr($data, 0, 100) . ")");
                return null;
            }
            
            // This log can be very verbose as getStreamInfo is called frequently.
            // Log::channel('ffmpeg')->debug("Stream {$streamKey}: Successfully retrieved stream info from Redis (" . strlen($data) . " bytes)");
            return $decoded;
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Stream {$streamKey}: Error retrieving stream info: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Set stream info in Redis
     */
    private function setStreamInfo(string $streamKey, array $streamInfo): void
    {
        try {
            $jsonData = json_encode($streamInfo);
            if ($jsonData === false) {
                Log::channel('ffmpeg')->error("Stream {$streamKey}: Failed to encode stream info to JSON: " . json_last_error_msg());
                return;
            }
            
            $result = $this->redis()->setex($streamKey, self::SEGMENT_EXPIRY, $jsonData);
            if (!$result) {
                Log::channel('ffmpeg')->error("Stream {$streamKey}: Failed to store stream info in Redis");
            } else {
                // This log can be verbose. Success is implicit if no error is thrown.
                // Log::channel('ffmpeg')->debug("Stream {$streamKey}: Successfully stored stream info in Redis (" . strlen($jsonData) . " bytes)");
            }
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Stream {$streamKey}: Error storing stream info: " . $e->getMessage());
        }
    }

    /**
     * Check if stream is active
     */
    private function isStreamActive(string $streamKey): bool
    {
        $streamInfo = $this->getStreamInfo($streamKey);
        return $streamInfo && ($streamInfo['status'] === 'active' || $streamInfo['status'] === 'starting');
    }

    /**
     * Increment client count for a stream
     */
    private function incrementClientCount(string $streamKey): void
    {
        $lock = Cache::lock('lock:stream_info_client_count:' . $streamKey, 10); // Lock for 10 seconds
        if ($lock->get()) {
            try {
                $streamInfo = $this->getStreamInfo($streamKey);
                if ($streamInfo) {
                    $streamInfo['client_count'] = ($streamInfo['client_count'] ?? 0) + 1;
                    $streamInfo['last_client_activity'] = now()->timestamp;
                    // If a client is joining, it's definitely not clientless anymore
                    unset($streamInfo['clientless_since']);
                    $this->setStreamInfo($streamKey, $streamInfo);
                    
                    // Update database client count
                    SharedStream::where('stream_id', $streamKey)->update([
                        'client_count' => $streamInfo['client_count'],
                        'last_client_activity' => now()
                    ]);
                    
                    Log::channel('ffmpeg')->debug("Incremented client count for {$streamKey} to {$streamInfo['client_count']}. Unset clientless_since if present.");
                }
            } finally {
                $lock->release();
            }
        } else {
            // Failed to acquire lock, log warning. Consider impact if this happens frequently.
            Log::channel('ffmpeg')->warning("Failed to acquire lock for incrementing client count on {$streamKey}. Client count may be temporarily inaccurate.");
            // Fallback: attempt non-locked increment or simply skip to avoid blocking indefinitely.
            // For simplicity here, we'll log and potentially accept a rare miscount under extreme contention.
            // A retry mechanism could be added if this proves problematic.
        }
    }

    /**
     * Register a client for a stream
     */
    private function registerClient(string $streamKey, string $clientId, array $options = []): void
    {
        $clientKey = self::CLIENT_PREFIX . $streamKey . ':' . $clientId;
        $clientInfo = [
            'client_id' => $clientId,
            'connected_at' => now()->timestamp,
            'last_client_activity' => now()->timestamp,
            'options' => $options
        ];
        $this->redis()->setex($clientKey, $this->getClientTimeoutResolved(), json_encode($clientInfo));
    }

    private function getClientTimeoutResolved(): int
    {
        return $this->clientTimeout;
    }

    /**
     * Get all active streams with their information and client counts
     * Called by BufferManagement job and other services
     */
    public function getAllActiveStreams(): array
    {
        try {
            $redis = $this->redis();
            $streams = [];
            
            // Get all stream keys from Redis - need to include the Redis database prefix in the pattern
            $redisPrefix = config('database.redis.options.prefix', '');
            $pattern = $redisPrefix . self::CACHE_PREFIX . '*';
            $streamKeys = $redis->keys($pattern);
            
            // Ensure we have an array before foreach
            if (is_array($streamKeys)) {
                foreach ($streamKeys as $fullKey) {
                    // Extract clean stream key by removing both the Redis database prefix and our cache prefix
                    $cleanFullKey = str_replace($redisPrefix, '', $fullKey);
                    $streamKey = str_replace(self::CACHE_PREFIX, '', $cleanFullKey);
                    $streamInfo = $this->getStreamInfo($cleanFullKey); // Use the key without Redis prefix for getStreamInfo
                    
                    if ($streamInfo && in_array($streamInfo['status'] ?? '', ['active', 'starting'])) {
                        // Get client count from Redis client keys - use the full key pattern for client search
                        $clientSearchKey = $cleanFullKey; // This already has the cache prefix but not the Redis prefix
                        $clientKeys = $redis->keys($redisPrefix . self::CLIENT_PREFIX . $clientSearchKey . ':*');
                        $clientCount = is_array($clientKeys) ? count($clientKeys) : 0;
                        
                        // Calculate uptime
                        $uptime = (time() - ($streamInfo['created_at'] ?? time()));
                        
                        $streams[$streamKey] = [
                            'stream_info' => $streamInfo,
                            'client_count' => $clientCount,
                            'uptime' => $uptime,
                            'last_activity' => $streamInfo['last_activity'] ?? time()
                        ];
                    }
                }
            }
            
            return $streams;
            
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Error getting all active streams: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Clean up old buffer segments for a stream
     */
    public function cleanupOldBufferSegments(string $streamKey): int
    {
        try {
            $bufferKey = self::BUFFER_PREFIX . $streamKey;
            $segmentNumbers = $this->redis()->lrange("{$bufferKey}:segments", 0, -1);
            
            $cleaned = 0;
            $keepCount = 50; // Keep only the most recent 50 segments
            
            // Ensure we have an array before processing
            if (is_array($segmentNumbers) && count($segmentNumbers) > $keepCount) {
                $toRemove = array_slice($segmentNumbers, $keepCount);
                foreach ($toRemove as $segmentNumber) {
                    $segmentKey = "{$bufferKey}:segment_{$segmentNumber}";
                    if ($this->redis()->del($segmentKey)) {
                        $cleaned++;
                    }
                }
                
                // Trim the segments list
                $this->redis()->ltrim("{$bufferKey}:segments", 0, $keepCount - 1);
            }
            
            return $cleaned;
            
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Error cleaning up buffer segments for {$streamKey}: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Optimize buffer size based on client count
     */
    public function optimizeBufferSize(string $streamKey, int $clientCount): bool
    {
        try {
            // Adjust buffer size based on client count
            $baseSegments = 30;
            $additionalSegments = min($clientCount * 5, 50); // Max 50 additional segments
            $targetSegments = $baseSegments + $additionalSegments;
            
            $bufferKey = self::BUFFER_PREFIX . $streamKey;
            $currentSegments = $this->redis()->llen("{$bufferKey}:segments");
            
            if ($currentSegments > $targetSegments) {
                // Trim to target size
                $this->redis()->ltrim("{$bufferKey}:segments", 0, $targetSegments - 1);
                return true;
            }
            
            return false;
            
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Error optimizing buffer size for {$streamKey}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get disk usage for a stream's buffers (Redis memory usage estimate)
     */
    public function getStreamBufferDiskUsage(string $streamKey): int
    {
        try {
            $bufferKey = self::BUFFER_PREFIX . $streamKey;
            $segmentNumbers = $this->redis()->lrange("{$bufferKey}:segments", 0, -1);
            
            $totalSize = 0;
            if (is_array($segmentNumbers)) {
                foreach ($segmentNumbers as $segmentNumber) {
                    $segmentKey = "{$bufferKey}:segment_{$segmentNumber}";
                    $data = $this->redis()->get($segmentKey);
                    if ($data) {
                        $totalSize += strlen($data);
                    }
                }
            }
            
            return $totalSize;
            
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Error getting buffer disk usage for {$streamKey}: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Trim buffer to specified size
     */
    public function trimBufferToSize(string $streamKey, int $targetSize): int
    {
        try {
            $bufferKey = self::BUFFER_PREFIX . $streamKey;
            $segmentNumbers = $this->redis()->lrange("{$bufferKey}:segments", 0, -1);
            
            $currentSize = 0;
            $freedSize = 0;
            $segmentsToKeep = [];
            
            // Ensure we have an array before processing
            if (is_array($segmentNumbers)) {
                // Calculate current size and determine which segments to keep
                foreach (array_reverse($segmentNumbers) as $segmentNumber) {
                    $segmentKey = "{$bufferKey}:segment_{$segmentNumber}";
                    $data = $this->redis()->get($segmentKey);
                    if ($data) {
                        $segmentSize = strlen($data);
                        if ($currentSize + $segmentSize <= $targetSize) {
                            $segmentsToKeep[] = $segmentNumber;
                            $currentSize += $segmentSize;
                        } else {
                            // Remove this segment
                            $this->redis()->del($segmentKey);
                            $freedSize += $segmentSize;
                        }
                    }
                }
                
                // Update the segments list
                if (!empty($segmentsToKeep)) {
                    $this->redis()->del("{$bufferKey}:segments");
                    foreach (array_reverse($segmentsToKeep) as $segmentNumber) {
                        $this->redis()->lpush("{$bufferKey}:segments", $segmentNumber);
                    }
                }
            }
            
            return $freedSize;
            
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Error trimming buffer for {$streamKey}: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Find orphaned buffer directories
     */
    public function findOrphanedBufferDirectories(): array
    {
        try {
            $orphanedDirs = [];
            $bufferPath = storage_path('app/shared_streams');
            
            if (!is_dir($bufferPath)) {
                return $orphanedDirs;
            }
            
            $activeStreams = $this->getAllActiveStreams();
            $activeStreamKeys = array_keys($activeStreams);
            
            $directories = glob($bufferPath . '/*', GLOB_ONLYDIR);
            foreach ($directories as $dir) {
                $dirName = basename($dir);
                
                // Check if this directory corresponds to an active stream
                $isActive = false;
                foreach ($activeStreamKeys as $streamKey) {
                    if (md5($streamKey) === $dirName) {
                        $isActive = true;
                        break;
                    }
                }
                
                if (!$isActive) {
                    $orphanedDirs[] = $dir;
                }
            }
            
            return $orphanedDirs;
            
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Error finding orphaned buffer directories: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get directory size
     */
    public function getDirectorySize(string $dir): int
    {
        try {
            $size = 0;
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            
            foreach ($iterator as $file) {
                $size += $file->getSize();
            }
            
            return $size;
            
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Error getting directory size for {$dir}: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Remove directory
     */
    public function removeDirectory(string $dir): bool
    {
        try {
            if (!is_dir($dir)) {
                return true;
            }
            
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            
            foreach ($iterator as $file) {
                if ($file->isDir()) {
                    rmdir($file->getRealPath());
                } else {
                    unlink($file->getRealPath());
                }
            }
            
            return rmdir($dir);
            
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Error removing directory {$dir}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Clean up temporary files
     */
    public function cleanupTempFiles(int $maxAge = 3600): int
    {
        try {
            $tempPath = storage_path('app/temp');
            $freedSize = 0;
            
            if (!is_dir($tempPath)) {
                return 0;
            }
            
            $cutoffTime = time() - $maxAge;
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($tempPath, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            
            foreach ($iterator as $file) {
                if ($file->getMTime() < $cutoffTime) {
                    $size = $file->getSize();
                    if (unlink($file->getRealPath())) {
                        $freedSize += $size;
                    }
                }
            }
            
            return $freedSize;
            
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Error cleaning up temp files: " . $e->getMessage());
            return 0;
        }
       }

    /**
     * Get total buffer disk usage across all streams
     */
    public function getTotalBufferDiskUsage(): int
    {
        try {
            $totalSize = 0;
            $activeStreams = $this->getAllActiveStreams();
            
            foreach (array_keys($activeStreams) as $streamKey) {
                $totalSize += $this->getStreamBufferDiskUsage($streamKey);
            }
            
            return $totalSize;
            
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Error getting total buffer disk usage: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Trim oldest buffers to reach target size
     */
    public function trimOldestBuffers(int $targetSize): int
    {
        try {
            $activeStreams = $this->getAllActiveStreams();
            $streamSizes = [];
            $totalSize = 0;
            
            // Calculate sizes for all streams
            foreach ($activeStreams as $streamKey => $streamData) {
                $size = $this->getStreamBufferDiskUsage($streamKey);
                $streamSizes[$streamKey] = [
                    'size' => $size,
                    'last_activity' => $streamData['last_activity'] ?? 0
                ];
                $totalSize += $size;
            }
            
            if ($totalSize <= $targetSize) {
                return 0;
            }
            
            // Sort by last activity (oldest first)
            uasort($streamSizes, function($a, $b) {
                return $a['last_activity'] <=> $b['last_activity'];
            });
            
            $freedSize = 0;
            $currentSize = $totalSize;
            
            foreach ($streamSizes as $streamKey => $data) {
                if ($currentSize <= $targetSize) {
                    break;
                }
                
                // Trim this stream's buffer by 50%
                $targetStreamSize = $data['size'] * 0.5;
                $freed = $this->trimBufferToSize($streamKey, (int)$targetStreamSize);
                $freedSize += $freed;
                $currentSize -= $freed;
            }
            
            return $freedSize;
            
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Error trimming oldest buffers: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Check if a process is currently running
     */
    private function isProcessRunning(?int $pid): bool
    {
        if (!$pid) {
            return false;
        }
        
        try {
            // Use kill -0 to check if process exists without actually killing it
            $result = shell_exec("kill -0 {$pid} 2>/dev/null; echo $?");
            $exitCode = (int)trim($result);
            
            // Exit code 0 means process exists and is running
            if ($exitCode === 0) {
                // Double-check that it's not a zombie process by checking its state
                $statusFile = "/proc/{$pid}/stat";
                if (file_exists($statusFile)) {
                    $stat = file_get_contents($statusFile);
                    if ($stat !== false) {
                        $parts = explode(' ', $stat);
                        if (isset($parts[2])) {
                            $state = $parts[2];
                            // Z = zombie, X = dead
                            if (in_array($state, ['Z', 'X'])) {
                                Log::channel('ffmpeg')->debug("Process {$pid} exists but is in state '{$state}' (zombie/dead)");
                                return false;
                            }
                        }
                    }
                }
                return true;
            }
            
            return false;
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Error checking if process {$pid} is running: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update stream activity timestamp
     */
    private function updateStreamActivity(string $streamKey): void
    {
        $streamInfo = $this->getStreamInfo($streamKey);
        if ($streamInfo) {
            $streamInfo['last_activity'] = now()->timestamp;
            $this->setStreamInfo($streamKey, $streamInfo);
        }
    }

    /**
     * Get the storage directory for a stream
     */
    private function getStreamStorageDir(string $streamKey): string
    {
        $bufferPath = config('proxy.shared_streaming.storage.buffer_path', 'shared_streams');
        $fullPath = storage_path('app/' . $bufferPath . '/' . $streamKey);
        return $fullPath;
    }

    /**
     * Track bandwidth usage for a stream
     */
    private function trackBandwidth(string $streamKey, int $bytesTransferred): void
    {
        try {
            // Get current time
            $now = time();
            
            // Get or initialize bandwidth tracking data
            $bandwidthKey = "bandwidth:{$streamKey}";
            $bandwidthData = Redis::get($bandwidthKey);
            
            if ($bandwidthData) {
                $data = json_decode($bandwidthData, true);
            } else {
                $data = [
                    'total_bytes' => 0,
                    'samples' => [],
                    'last_update' => $now
                ];
            }
            
            // Add bytes to total
            $data['total_bytes'] += $bytesTransferred;
            
            // Add sample for bandwidth calculation (keep last 10 samples)
            $data['samples'][] = [
                'timestamp' => $now,
                'bytes' => $bytesTransferred
            ];
            
            // Keep only last 10 samples for bandwidth calculation
            if (count($data['samples']) > 10) {
                $data['samples'] = array_slice($data['samples'], -10);
            }
            
            // Calculate current bandwidth (kbps) over last 10 seconds
            $recentSamples = array_filter($data['samples'], function($sample) use ($now) {
                return ($now - $sample['timestamp']) <= 10;
            });
            
            if (count($recentSamples) > 1) {
                $totalBytes = array_sum(array_column($recentSamples, 'bytes'));
                $timeSpan = max(1, $now - min(array_column($recentSamples, 'timestamp')));
                $bandwidthKbps = ($totalBytes * 8) / ($timeSpan * 1000); // Convert to kbps
            } else {
                $bandwidthKbps = 0;
            }
            
            $data['last_update'] = $now;
            $data['current_bandwidth_kbps'] = round($bandwidthKbps, 2);
            
            // Store bandwidth data in Redis (expires in 1 hour)
            Redis::setex($bandwidthKey, 3600, json_encode($data));
            
            // Update database periodically (every 10 seconds to avoid too many writes)
            if ($now % 10 === 0) {
                SharedStream::where('stream_id', $streamKey)->update([
                    'bytes_transferred' => $data['total_bytes'],
                    'bandwidth_kbps' => (int)$bandwidthKbps
                ]);
            }
            
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Error tracking bandwidth for {$streamKey}: " . $e->getMessage());
        }
    }

    /**
     * Update client activity timestamp
     */
    private function updateClientActivity(string $streamKey, string $clientId): void
    {
        $clientKey = "stream_clients:{$streamKey}";
        $clientData = Redis::hget($clientKey, $clientId);
        
        if ($clientData) {
            $clientInfo = json_decode($clientData, true);
            $clientInfo['last_activity'] = now()->timestamp;
            Redis::hset($clientKey, $clientId, json_encode($clientInfo));
        }
    }

    /**
     * Update stream status
     */
    private function updateStreamStatus(string $streamKey, string $status): void
    {
        $streamInfo = $this->getStreamInfo($streamKey);
        if ($streamInfo) {
            $streamInfo['status'] = $status;
            $this->setStreamInfo($streamKey, $streamInfo);
            
            // Also update database
            SharedStream::where('stream_id', $streamKey)->update(['status' => $status]);
        }
    }

    /**
     * Cleanup stream data
     */
    private function cleanupStream(string $streamKey, bool $removeFiles = false): void
    {
        // Clean up active process if it exists
        if (isset($this->activeProcesses[$streamKey])) {
            $processInfo = $this->activeProcesses[$streamKey];
            
            // Close file handles
            if (is_resource($processInfo['stdout'])) {
                fclose($processInfo['stdout']);
            }
            if (is_resource($processInfo['stderr'])) {
                fclose($processInfo['stderr']);
            }
            
            // Close the process
            if (is_resource($processInfo['process'])) {
                proc_close($processInfo['process']);
            }
            
            unset($this->activeProcesses[$streamKey]);
            Log::channel('ffmpeg')->debug("Stream {$streamKey}: Cleaned up active process handles");
        }
        
        // Remove Redis data
        Redis::del("shared_stream:{$streamKey}");
        Redis::del("stream_clients:{$streamKey}");
        Redis::del("stream_buffer:{$streamKey}");
        
        // Clean up files if requested
        if ($removeFiles) {
            $storageDir = $this->getStreamStorageDir($streamKey);
            if (is_dir($storageDir)) {
                $this->removeDirectory($storageDir);
            }
        }
        
        // Update database
        SharedStream::where('stream_id', $streamKey)->update([
            'status' => 'stopped',
            'stopped_at' => now(),
            'client_count' => 0,
            'bandwidth_kbps' => 0,
            'process_id' => null
        ]);
    }

    /**
     * Cleanup inactive streams and disconnected clients
     */
    public function cleanupInactiveStreams(): array
    {
        $cleanedStreams = 0;
        $cleanedClients = 0;
        
        try {
            // Get all shared stream keys
            $pattern1 = 'shared_stream:*';
            $pattern2 = '*shared_stream:*';
            
            $keys1 = Redis::keys($pattern1);
            $keys2 = Redis::keys($pattern2);
            $keys = array_merge($keys1, $keys2);
            
            $inactiveThreshold = now()->subMinutes(30)->timestamp; // 30 minutes
            $deadProcessThreshold = now()->subMinutes(5)->timestamp; // 5 minutes for dead processes
            
            foreach ($keys as $key) {
                $streamKey = str_replace([
                    config('database.redis.options.prefix', ''),
                    'shared_stream:'
                ], '', $key);
                
                $redisData = Redis::get($key);
                $streamData = $redisData ? json_decode($redisData, true) : null;
                
                if (!$streamData) {
                    continue;
                }
                
                $shouldCleanup = false;
                $lastActivity = $streamData['last_activity'] ?? 0;
                $status = $streamData['status'] ?? 'unknown';
                
                // Check if stream is inactive
                if ($lastActivity < $inactiveThreshold) {
                    Log::info("Stream {$streamKey} inactive since " . date('Y-m-d H:i:s', $lastActivity));
                    $shouldCleanup = true;
                }
                
                // Check if process is dead
                $pid = $this->getProcessPid($streamKey);
                if ($pid && !$this->isProcessRunning($pid) && $lastActivity < $deadProcessThreshold) {
                    Log::info("Stream {$streamKey} has dead process (PID: {$pid})");
                    $shouldCleanup = true;
                }
                
                // Check if status indicates error or stopped
                if (in_array($status, ['error', 'stopped'])) {
                    Log::info("Stream {$streamKey} has status: {$status}");
                    $shouldCleanup = true;
                }
                
                if ($shouldCleanup) {
                    // Count clients before cleanup
                    $clientKey = "stream_clients:{$streamKey}";
                    $clients = Redis::hgetall($clientKey);
                    $cleanedClients += count($clients);
                    
                    // Cleanup the stream
                    $this->cleanupStream($streamKey, true);
                    $cleanedStreams++;
                    
                    Log::info("Cleaned up inactive stream: {$streamKey}");
                } else {
                    // Clean up disconnected clients for active streams
                    $clientKey = "stream_clients:{$streamKey}";
                    $clients = Redis::hgetall($clientKey);
                    
                    foreach ($clients as $clientId => $clientDataJson) {
                        $clientData = $clientDataJson ? json_decode($clientDataJson, true) : null;
                        if (!$clientData) {
                            continue;
                        }
                        $clientLastActivity = $clientData['last_activity'] ?? 0;
                        
                        if ($clientLastActivity < $inactiveThreshold) {
                            Redis::hdel($clientKey, $clientId);
                            $cleanedClients++;
                            Log::info("Removed inactive client {$clientId} from stream {$streamKey}");
                        }
                    }
                    
                    // Update stream client count
                    $remainingClients = Redis::hlen($clientKey);
                    $streamData['client_count'] = $remainingClients;
                    $this->setStreamInfo($streamKey, $streamData);
                    
                    // Update database
                    SharedStream::where('stream_id', $streamKey)
                        ->update(['client_count' => $remainingClients]);
                }
            }
            
            Log::info("Cleanup completed: {$cleanedStreams} streams, {$cleanedClients} clients");
            
        } catch (\Exception $e) {
            Log::error('Error during stream cleanup: ' . $e->getMessage());
        }
        
        return [
            'cleaned_streams' => $cleanedStreams,
            'cleaned_clients' => $cleanedClients
        ];
    }

    /**
     * Dummy method to satisfy interface or parent class
     */
    public function dummyMethod(): void
    {
        // No operation
    }

    /**
     * Get next stream segments for a client
     */
    public function getNextStreamSegments(string $streamKey, string $clientId, int &$lastSegment): ?string
    {
        $bufferKey = self::BUFFER_PREFIX . $streamKey;
        $segmentNumbers = $this->redis()->lrange("{$bufferKey}:segments", 0, -1);
        
        $data = '';
        $segmentsRetrieved = 0;
        $maxSegmentsPerCall = 5; // Limit segments per call to prevent overwhelming clients
        
        // First, try to get data from existing buffered segments
        if (!empty($segmentNumbers)) {
            // Convert segment numbers to integers and sort them
            $segmentNumbers = array_map('intval', $segmentNumbers);
            sort($segmentNumbers);
            
            foreach ($segmentNumbers as $segmentNumber) {
                // Only get segments newer than the last one sent to this client

                if ($segmentNumber > $lastSegment && $segmentsRetrieved < $maxSegmentsPerCall) {
                    $segmentKey = "{$bufferKey}:segment_{$segmentNumber}";
                    $segmentData = $this->redis()->get($segmentKey);
                    if ($segmentData) {
                        $data .= $segmentData;
                        $lastSegment = $segmentNumber; // Update the reference variable
                        $segmentsRetrieved++;
                    }
                }
            }
        }
        
        // If no buffered data is available, try to read directly from FFmpeg
        if (empty($data) && isset($this->activeProcesses[$streamKey])) {
            $data = $this->readDirectFromFFmpeg($streamKey, $lastSegment);
        }
        
        // Update client activity if we retrieved data
        if (!empty($data)) {
            $this->updateClientActivity($streamKey, $clientId);
            $this->trackBandwidth($streamKey, strlen($data));
        }
        
        return !empty($data) ? $data : null;
    }

    /**
     * Read data directly from FFmpeg process
     */
    private function readDirectFromFFmpeg(string $streamKey, int &$lastSegment): ?string
    {
        if (!isset($this->activeProcesses[$streamKey])) {
            return null;
        }
        
        $processInfo = $this->activeProcesses[$streamKey];
        $stdout = $processInfo['stdout'];
        $stderr = $processInfo['stderr'];
        $process = $processInfo['process'];
        
        // Check if process is still running
        $status = proc_get_status($process);
        if (!$status['running']) {
            Log::channel('ffmpeg')->info("Stream {$streamKey}: FFmpeg process ended, removing from active processes");
            unset($this->activeProcesses[$streamKey]);
            return null;
        }
        
        $bufferKey = self::BUFFER_PREFIX . $streamKey;
        $redis = $this->redis();
        $targetChunkSize = 188 * 1000; // 188KB chunks
        $accumulatedData = '';
        $accumulatedSize = 0;
        $maxReadTime = 2; // Maximum 2 seconds to read data
        $startTime = time();
        $readAttempts = 0;
        $maxReadAttempts = 20; // Maximum read attempts
        
        Log::channel('ffmpeg')->debug("Stream {$streamKey}: Reading directly from FFmpeg process");
        
        while ($accumulatedSize < $targetChunkSize && 
               (time() - $startTime) < $maxReadTime && 
               $readAttempts < $maxReadAttempts) {
            
            $readAttempts++;
            
            // Try to read data from FFmpeg stdout
            $chunk = fread($stdout, 32768); // Read 32KB chunks
            if ($chunk !== false && strlen($chunk) > 0) {
                $accumulatedData .= $chunk;
                $accumulatedSize += strlen($chunk);
                
                Log::channel('ffmpeg')->debug("Stream {$streamKey}: Read " . strlen($chunk) . " bytes from FFmpeg (total: {$accumulatedSize})");
            } else {
                // No immediate data available, small sleep
                usleep(100000); // 100ms
            }
            
            // Check for errors
            $error = fread($stderr, 1024);
            if ($error !== false && strlen($error) > 0) {
                Log::channel('ffmpeg')->error("Stream {$streamKey}: FFmpeg error: {$error}");
            }
        }
        
        if ($accumulatedSize > 0) {
            // Store the data as a new segment
            $currentSegments = $redis->llen("{$bufferKey}:segments");
            $newSegmentNumber = $currentSegments;
            
            $segmentKey = "{$bufferKey}:segment_{$newSegmentNumber}";
            $redis->setex($segmentKey, self::SEGMENT_EXPIRY, $accumulatedData);
            $redis->lpush("{$bufferKey}:segments", $newSegmentNumber);
            
            // Keep only recent segments (prevent memory bloat)
            $redis->ltrim("{$bufferKey}:segments", 0, 50);
            
            // Clean up old segments
            $oldSegmentNumber = $newSegmentNumber - 50;
            if ($oldSegmentNumber >= 0) {
                $redis->del("{$bufferKey}:segment_{$oldSegmentNumber}");
            }
            
            $lastSegment = $newSegmentNumber;
            Log::channel('ffmpeg')->debug("Stream {$streamKey}: Stored {$accumulatedSize} bytes as segment {$newSegmentNumber}");
            
            return $accumulatedData;
        }
        
        return null;
    }

    /**
     * Get stream statistics/status information
     */
    public function getStreamStats(string $streamKey): ?array
    {
        $streamInfo = $this->getStreamInfo($streamKey);
        if (!$streamInfo) {
            return null;
        }

        return [
            'status' => $streamInfo['status'] ?? 'unknown',
            'client_count' => $streamInfo['client_count'] ?? 0,
            'created_at' => $streamInfo['created_at'] ?? null,
            'last_activity' => $streamInfo['last_activity'] ?? null,
            'process_id' => $streamInfo['process_id'] ?? null,
            'uptime' => isset($streamInfo['created_at']) ? (time() - $streamInfo['created_at']) : 0
        ];
    }
}
