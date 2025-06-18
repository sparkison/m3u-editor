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
    const SEGMENT_EXPIRY = 300; // 5 minutes
    const CLIENT_TIMEOUT = 30; // 30 seconds

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
            // Join existing stream
            Log::channel('ffmpeg')->debug("Client {$clientId} joining existing shared stream {$streamKey}");
            $this->incrementClientCount($streamKey);
            $isNewStream = false;
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
            'last_activity' => now()->timestamp,
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

        // Start the streaming process in background (non-blocking)
        try {
            // Dispatch the stream startup as a background job to prevent HTTP timeout
            \App\Jobs\StreamStarter::dispatch($streamKey, $streamInfo);
            Log::channel('ffmpeg')->debug("Dispatched stream starter job for {$streamKey}");
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->warning("Failed to dispatch stream starter job for {$streamKey}, starting inline: " . $e->getMessage());
            // Fallback to inline startup (with timeout risk)
            $this->startStreamingProcess($streamKey, $streamInfo);
        }

        Log::channel('ffmpeg')->debug("Created new shared stream {$streamKey} for {$type} {$title}");

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

        // Use proc_open for direct streaming
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $pipes = [];
        $process = proc_open($cmd, $descriptors, $pipes);
        
        if (!is_resource($process)) {
            throw new \Exception("Failed to start direct stream process for {$streamKey}");
        }

        // Close stdin (we don't write to FFmpeg)
        fclose($pipes[0]);

        // Get the PID and store it
        $status = proc_get_status($process);
        $pid = $status['pid'];
        $this->setStreamProcess($streamKey, $pid);

        // Store process info in stream data
        $streamInfo['pid'] = $pid;
        $this->setStreamInfo($streamKey, $streamInfo);

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
        
        // Better error handling (from working approach)
        $cmd .= '-err_detect ignore_err -ignore_unknown ';
        
        // HTTP options (simplified to match working approach)
        $cmd .= "-user_agent " . escapeshellarg($userAgent) . " -referer " . escapeshellarg("MyComputer") . " ";
        $cmd .= '-multiple_requests 1 -reconnect_on_network_error 1 ';
        $cmd .= '-reconnect_on_http_error 5xx,4xx -reconnect_streamed 1 ';
        $cmd .= '-reconnect_delay_max 5 ';
        $cmd .= '-noautorotate ';
        
        // Input
        $cmd .= '-i ' . escapeshellarg($streamUrl) . ' ';
        
        // Output options - simplified to match working direct streaming
        $videoCodec = $settings['ffmpeg_codec_video'] ?? 'copy';
        $audioCodec = $settings['ffmpeg_codec_audio'] ?? 'copy';
        
        // Use copy by default for shared streaming to reduce CPU load
        if ($videoCodec === 'copy' || empty($videoCodec)) {
            $videoCodec = 'copy';
        }
        if ($audioCodec === 'copy' || empty($audioCodec)) {
            $audioCodec = 'copy';
        }
        
        // Output format (simplified to match working approach)
        $cmd .= "-c:v {$videoCodec} -c:a {$audioCodec} -f mpegts pipe:1";
        
        Log::channel('ffmpeg')->debug("SharedStream: Built simplified direct command matching working approach");
        
        return $cmd;
    }

    /**
     * Setup error logging for FFmpeg stderr
     */
    private function setupErrorLogging(string $streamKey, $stderr, $process): void
    {
        // Start background monitoring of the process
        $this->monitorProcessHealth($streamKey, $stderr, $process);
        
        // Register shutdown function to handle stderr and process cleanup
        register_shutdown_function(function () use ($streamKey, $stderr, $process) {
            $logger = Log::channel('ffmpeg');
            
            // Drain stderr
            while (!feof($stderr)) {
                $line = fgets($stderr);
                if ($line !== false && !empty(trim($line))) {
                    $logger->error("Stream {$streamKey}: " . trim($line));
                }
            }
            
            fclose($stderr);
            
            // Clean up process
            if (is_resource($process)) {
                proc_close($process);
            }
            
            // Mark stream as stopped if process ended
            $this->cleanupStream($streamKey, true);
        });
    }

    /**
     * Monitor process health and detect failures early
     */
    private function monitorProcessHealth(string $streamKey, $stderr, $process): void
    {
        // Schedule a delayed check to validate the process is still running
        // Using a simple approach without jobs for immediate processing
        $this->scheduleHealthCheck($streamKey, $stderr, $process);
    }

    /**
     * Schedule a health check for the process
     */
    private function scheduleHealthCheck(string $streamKey, $stderr, $process): void
    {
        // Use a simple background process approach
        if (function_exists('pcntl_fork')) {
            $pid = pcntl_fork();
            if ($pid == 0) {
                // Child process
                sleep(2); // Give FFmpeg a moment to initialize
                $this->performHealthCheck($streamKey, $stderr, $process);
                exit(0);
            }
        } else {
            // Fallback: perform immediate check after a delay
            // This is not ideal but works for development
            register_shutdown_function(function () use ($streamKey, $stderr, $process) {
                sleep(1);
                $this->performHealthCheck($streamKey, $stderr, $process);
            });
        }
    }

    /**
     * Perform the actual health check
     */
    private function performHealthCheck(string $streamKey, $stderr, $process): void
    {
        $status = proc_get_status($process);
        $isRunning = $status['running'];
        
        if (!$isRunning) {
            Log::channel('ffmpeg')->warning("Stream {$streamKey}: FFmpeg process failed during startup");
            
            // Read any error output
            $errorOutput = '';
            if (is_resource($stderr)) {
                while (!feof($stderr)) {
                    $line = fgets($stderr);
                    if ($line !== false && !empty(trim($line))) {
                        $errorOutput .= trim($line) . "\n";
                    }
                }
            }
            
            if (!empty($errorOutput)) {
                Log::channel('ffmpeg')->error("Stream {$streamKey} startup errors: " . $errorOutput);
            }
            
            // Mark stream as failed
            $this->markStreamAsFailed($streamKey, 'FFmpeg process failed during startup');
        } else {
            Log::channel('ffmpeg')->debug("Stream {$streamKey}: FFmpeg process health check passed");
        }
    }

    /**
     * Mark a stream as failed and clean up
     */
    private function markStreamAsFailed(string $streamKey, string $reason): void
    {
        // Update stream status
        $streamInfo = $this->getStreamInfo($streamKey);
        if ($streamInfo) {
            $streamInfo['status'] = 'failed';
            $streamInfo['error'] = $reason;
            $this->setStreamInfo($streamKey, $streamInfo);
        }
        
        // Update database status
        SharedStream::where('stream_id', $streamKey)->update([
            'status' => 'failed',
            'error_message' => $reason
        ]);
        
        // Clean up resources
        $this->cleanupStream($streamKey, true);
        
        Log::channel('ffmpeg')->error("Stream {$streamKey} marked as failed: {$reason}");
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
        
        // Use a background approach that doesn't block the main thread
        $this->runAsyncBufferManager($streamKey, $stdout, $stderr, $process);
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
        
        // Use a register_shutdown_function approach instead of forking
        // This prevents broken pipe issues caused by parent process file handle closure
        register_shutdown_function(function () use ($streamKey, $stdout, $stderr, $process) {
            $this->drainPipesOnShutdown($streamKey, $stdout, $stderr, $process);
        });
        
        // Start continuous buffering immediately in current process
        // This prevents the broken pipe issue by keeping the parent process active
        $this->runBufferManager($streamKey, $stdout, $stderr, $process);
        
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
        // Set up a lightweight buffer reader to prevent pipe overflow
        register_shutdown_function(function () use ($streamKey, $stdout, $stderr, $process) {
            $this->drainPipesOnShutdown($streamKey, $stdout, $stderr, $process);
        });
        
        Log::channel('ffmpeg')->debug("Stream {$streamKey}: Set up direct buffer management with shutdown drain");
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

        Log::channel('ffmpeg')->debug("Stream {$streamKey}: Starting initial buffering (waiting for FFmpeg data)");

        // Wait for FFmpeg to start producing data and build initial buffer
        $accumulatedData = '';
        $accumulatedSize = 0;
        $hasFirstData = false;
        $redis = $this->redis();

        while ($waitTime < $maxWait) {
            // Try to read data in larger chunks for better performance
            $chunk = fread($stdout, $readChunkSize);
            if ($chunk !== false && strlen($chunk) > 0) {
                if (!$hasFirstData) {
                    Log::channel('ffmpeg')->info("Stream {$streamKey}: First data received! Starting initial buffer accumulation");
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
                    
                    $segmentNumber++;
                    $accumulatedData = '';
                    $accumulatedSize = 0;
                    
                    // Build up fewer initial segments for faster startup
                    if ($segmentNumber >= 2) { // Reduced from 3 to 2 for faster startup
                        break;
                    }
                }
            } else {
                // No immediate data available, check for errors and wait less
                $error = fread($stderr, 1024);
                if ($error !== false && strlen($error) > 0) {
                    Log::channel('ffmpeg')->error("Stream {$streamKey} FFmpeg error during initial buffering: {$error}");
                }
                
                usleep(100000); // Reduced from 200ms to 100ms for faster startup
                $waitTime += 0.1;
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
            
            // Update stream status to active since we have data
            $streamInfo = $this->getStreamInfo($streamKey);
            if ($streamInfo) {
                $streamInfo['status'] = 'active';
                $streamInfo['first_data_at'] = time();
                $streamInfo['initial_segments'] = $segmentNumber;
                $this->setStreamInfo($streamKey, $streamInfo);
                
                // Also update database status
                SharedStream::where('stream_id', $streamKey)->update([
                    'status' => 'active'
                ]);
                
                Log::channel('ffmpeg')->info("Stream {$streamKey}: Status updated to 'active' - stream is ready for clients");
            }
        } else {
            // Even if no initial buffering succeeded, mark stream as starting so clients can attempt to connect
            // This handles cases where FFmpeg takes time to start producing data
            Log::channel('ffmpeg')->warning("Stream {$streamKey}: No initial data received after {$maxWait}s, marking as starting for client attempts");
            
            $streamInfo = $this->getStreamInfo($streamKey);
            if ($streamInfo) {
                $streamInfo['status'] = 'starting'; // Changed from 'error' to 'starting'
                $streamInfo['warning_message'] = 'No initial data received, stream may take time to start';
                $this->setStreamInfo($streamKey, $streamInfo);
                
                // Update database status to starting instead of error
                SharedStream::where('stream_id', $streamKey)->update([
                    'status' => 'starting',
                    'error_message' => null
                ]);
                
                Log::channel('ffmpeg')->info("Stream {$streamKey}: Status updated to 'starting' - allowing client attempts despite no initial data");
            }
        }
    }

    /**
     * Run the buffer manager (reads from FFmpeg, stores in Redis for sharing)
     * Optimized for performance and VLC compatibility
     */
    private function runBufferManager(string $streamKey, $stdout, $stderr, $process): void
    {
        $bufferKey = self::BUFFER_PREFIX . $streamKey;
        $segmentNumber = 0;
        
        // Optimized buffer settings based on xTeVe approach
        $targetChunkSize = 188 * 1000; // 188KB chunks (similar to xTeVe default)
        $readChunkSize = 32768; // 32KB reads for better performance
        $maxSegmentsInMemory = 100; // Keep more segments for better client experience
        
        $lastActivity = time();
        $maxInactiveTime = 60; // 60 seconds of no data before giving up

        Log::channel('ffmpeg')->debug("Stream {$streamKey}: Buffer manager starting with {$targetChunkSize} byte chunks, {$readChunkSize} read size");

        try {
            $accumulatedData = '';
            $accumulatedSize = 0;
            $lastFlushTime = time();
            $maxAccumulationTime = 1; // Max 1 second before force-flushing (more responsive)
            $redis = $this->redis();

            while (!feof($stdout) && $this->isStreamActive($streamKey)) {
                $hasData = false;

                // Read larger chunks for better performance
                $chunk = fread($stdout, $readChunkSize);
                if ($chunk !== false && strlen($chunk) > 0) {
                    $accumulatedData .= $chunk;
                    $accumulatedSize += strlen($chunk);
                    $hasData = true;
                    $lastActivity = time();
                    
                    // More aggressive flushing for better responsiveness
                    $shouldFlush = false;
                    
                    // Flush when we have enough data for a good chunk
                    if ($accumulatedSize >= $targetChunkSize) {
                        $shouldFlush = true;
                    }
                    // Flush if we've been accumulating for too long (prevents delays)
                    elseif (time() - $lastFlushTime >= $maxAccumulationTime) {
                        $shouldFlush = true;
                    }
                    // Flush if we have reasonable amount and no immediate data
                    elseif ($accumulatedSize >= 64000) { // 64KB minimum for reasonable chunks
                        // Check if more data is immediately available
                        stream_set_blocking($stdout, false);
                        $peek = fread($stdout, 1);
                        if ($peek === false || strlen($peek) === 0) {
                            $shouldFlush = true;
                        } else {
                            // Put the peeked byte back into accumulated data
                            $accumulatedData .= $peek;
                            $accumulatedSize += 1;
                        }
                    }
                    
                    if ($shouldFlush && $accumulatedSize > 0) {
                        // Use pipeline for better Redis performance
                        $pipeline = $redis->pipeline();
                        
                        $segmentKey = "{$bufferKey}:segment_{$segmentNumber}";
                        $pipeline->setex($segmentKey, self::SEGMENT_EXPIRY, $accumulatedData);
                        $pipeline->lpush("{$bufferKey}:segments", $segmentNumber);
                        $pipeline->ltrim("{$bufferKey}:segments", 0, $maxSegmentsInMemory);
                        
                        // Update stream activity
                        $pipeline->setex("{$bufferKey}:activity", 300, time());
                        
                        $pipeline->execute();
                        
                        // Log progress less frequently to reduce overhead
                        if ($segmentNumber <= 10 || $segmentNumber % 200 === 0) {
                            Log::channel('ffmpeg')->debug("Stream {$streamKey}: Buffered segment {$segmentNumber} ({$accumulatedSize} bytes)");
                        }
                        
                        $segmentNumber++;
                        $accumulatedData = '';
                        $accumulatedSize = 0;
                        $lastFlushTime = time();
                    }
                }

                // Check for errors from FFmpeg stderr (less frequently)
                if ($segmentNumber % 10 === 0) { // Check every 10 segments
                    $error = fread($stderr, 1024);
                    if ($error !== false && strlen($error) > 0) {
                        Log::channel('ffmpeg')->error("Stream {$streamKey} FFmpeg error: {$error}");
                    }
                }

                // Check if process is still running (less frequently)
                if ($segmentNumber % 50 === 0 || !$hasData) { // Check every 50 segments or when no data
                    $status = proc_get_status($process);
                    if (!$status['running']) {
                        // Flush any remaining accumulated data before ending
                        if ($accumulatedSize > 0) {
                            $segmentKey = "{$bufferKey}:segment_{$segmentNumber}";
                            $redis->setex($segmentKey, self::SEGMENT_EXPIRY, $accumulatedData);
                            $redis->lpush("{$bufferKey}:segments", $segmentNumber);
                            $redis->ltrim("{$bufferKey}:segments", 0, $maxSegmentsInMemory);
                            $segmentNumber++;
                            Log::channel('ffmpeg')->debug("Stream {$streamKey}: Flushed final segment {$segmentNumber} ({$accumulatedSize} bytes)");
                        }
                        Log::channel('ffmpeg')->warning("Stream {$streamKey}: FFmpeg process terminated (exit code: {$status['exitcode']})");
                        break;
                    }
                }

                // Optimized sleep timing for better responsiveness
                if (!$hasData) {
                    if (time() - $lastActivity > $maxInactiveTime) {
                        // Flush any remaining data before ending
                        if ($accumulatedSize > 0) {
                            $segmentKey = "{$bufferKey}:segment_{$segmentNumber}";
                            $redis->setex($segmentKey, self::SEGMENT_EXPIRY, $accumulatedData);
                            $redis->lpush("{$bufferKey}:segments", $segmentNumber);
                            $redis->ltrim("{$bufferKey}:segments", 0, $maxSegmentsInMemory);
                            $segmentNumber++;
                            Log::channel('ffmpeg')->debug("Stream {$streamKey}: Flushed timeout segment {$segmentNumber} ({$accumulatedSize} bytes)");
                        }
                        Log::channel('ffmpeg')->warning("Stream {$streamKey}: No data for {$maxInactiveTime}s, ending buffer manager");
                        break;
                    }
                    usleep(10000); // 10ms sleep when no data (faster response than before)
                } else {
                    usleep(500); // 0.5ms sleep when actively buffering (very responsive)
                }
            }
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Stream {$streamKey}: Buffer manager error: " . $e->getMessage());
        } finally {
            // Cleanup resources
            if (is_resource($stdout)) {
                fclose($stdout);
            }
            if (is_resource($stderr)) {
                fclose($stderr);
            }
            if (is_resource($process)) {
                proc_close($process);
            }
            
            // Mark stream as stopped
            $this->cleanupStream($streamKey, true);
            Log::channel('ffmpeg')->info("Stream {$streamKey}: Buffer manager ended after {$segmentNumber} segments");
        }
    }

    /**
     * Run buffer manager asynchronously using xTeVe-inspired approach
     */
    private function runAsyncBufferManager(string $streamKey, $stdout, $stderr, $process): void
    {
        // Use a simple approach that doesn't block the main HTTP response
        // Similar to xTeVe's buffer management approach
        
        if (function_exists('pcntl_fork')) {
            $pid = pcntl_fork();
            if ($pid == 0) {
                // Child process - run buffer manager
                $this->runBufferManager($streamKey, $stdout, $stderr, $process);
                exit(0);
            } elseif ($pid > 0) {
                // Parent process - continue
                Log::channel('ffmpeg')->debug("Stream {$streamKey}: Buffer manager forked to PID {$pid}");
                return;
            }
        }
        
        // Fallback for systems without pcntl_fork
        // Run buffer manager in current process but return quickly
        $this->runOptimizedBufferManager($streamKey, $stdout, $stderr, $process);
    }

    /**
     * Optimized buffer manager inspired by xTeVe's approach
     */
    private function runOptimizedBufferManager(string $streamKey, $stdout, $stderr, $process): void
    {
        // Set up immediate non-blocking streams
        stream_set_blocking($stdout, false);
        stream_set_blocking($stderr, false);
        
        // Register a shutdown function to handle cleanup
        register_shutdown_function(function () use ($streamKey, $stdout, $stderr, $process) {
            $this->performFinalCleanup($streamKey, $stdout, $stderr, $process);
        });
        
        // Start a background buffer reader using stream_select for efficiency
        $this->startStreamSelector($streamKey, $stdout, $stderr, $process);
    }

    /**
     * Use stream_select for efficient I/O handling (xTeVe-style)
     */
    private function startStreamSelector(string $streamKey, $stdout, $stderr, $process): void
    {
        $bufferKey = self::BUFFER_PREFIX . $streamKey;
        $segmentNumber = 0;
        $accumulatedData = '';
        $accumulatedSize = 0;
        $targetChunkSize = 188 * 1000; // xTeVe-compatible chunk size
        $redis = $this->redis();
        
        Log::channel('ffmpeg')->debug("Stream {$streamKey}: Starting stream selector buffer manager");
        
        $maxIterations = 100; // Limit iterations to prevent hanging
        $iteration = 0;
        
        while ($iteration < $maxIterations && $this->isStreamActive($streamKey)) {
            $read = [$stdout, $stderr];
            $write = null;
            $except = null;
            
            // Use stream_select with short timeout for responsiveness
            $result = stream_select($read, $write, $except, 0, 100000); // 100ms timeout
            
            if ($result > 0) {
                // Data available on stdout
                if (in_array($stdout, $read)) {
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
                            
                            $segmentNumber++;
                            $accumulatedData = '';
                            $accumulatedSize = 0;
                            
                            // Update stream activity
                            $this->updateStreamActivity($streamKey);
                        }
                    }
                }
                
                // Handle stderr
                if (in_array($stderr, $read)) {
                    $error = fread($stderr, 1024);
                    if ($error !== false && strlen($error) > 0) {
                        Log::channel('ffmpeg')->error("Stream {$streamKey}: {$error}");
                    }
                }
            }
            
            $iteration++;
            
            // Check if process is still running every 10 iterations
            if ($iteration % 10 === 0) {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    Log::channel('ffmpeg')->info("Stream {$streamKey}: FFmpeg process ended, stopping buffer manager");
                    break;
                }
            }
        }
        
        // Flush any remaining data
        if ($accumulatedSize > 0) {
            $segmentKey = "{$bufferKey}:segment_{$segmentNumber}";
            $redis->setex($segmentKey, self::SEGMENT_EXPIRY, $accumulatedData);
            $redis->lpush("{$bufferKey}:segments", $segmentNumber);
            $segmentNumber++;
        }
        
        Log::channel('ffmpeg')->debug("Stream {$streamKey}: Stream selector completed with {$segmentNumber} segments");
    }

    /**
     * Perform final cleanup on shutdown
     */
    private function performFinalCleanup(string $streamKey, $stdout, $stderr, $process): void
    {
        try {
            // Quick drain of any remaining data
            if (is_resource($stdout)) {
                while (!feof($stdout)) {
                    $data = fread($stdout, 8192);
                    if ($data === false || strlen($data) === 0) break;
                }
                fclose($stdout);
            }
            
            if (is_resource($stderr)) {
                while (!feof($stderr)) {
                    $error = fread($stderr, 1024);
                    if ($error === false || strlen($error) === 0) break;
                    if (!empty(trim($error))) {
                        Log::channel('ffmpeg')->error("Stream {$streamKey} final: {$error}");
                    }
                }
                fclose($stderr);
            }
            
            if (is_resource($process)) {
                proc_close($process);
            }
            
            // Mark stream as stopped
            $streamInfo = $this->getStreamInfo($streamKey);
            if ($streamInfo) {
                $streamInfo['status'] = 'stopped';
                $this->setStreamInfo($streamKey, $streamInfo);
            }
            
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Stream {$streamKey}: Error during final cleanup: " . $e->getMessage());
        }
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
        $data = $this->redis()->get($streamKey);
        return $data ? json_decode($data, true) : null;
    }

    /**
     * Set stream info in Redis
     */
    private function setStreamInfo(string $streamKey, array $streamInfo): void
    {
        $this->redis()->setex($streamKey, self::SEGMENT_EXPIRY, json_encode($streamInfo));
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
        $streamInfo = $this->getStreamInfo($streamKey);
        if ($streamInfo) {
            $streamInfo['client_count'] = ($streamInfo['client_count'] ?? 0) + 1;
            $streamInfo['last_activity'] = now()->timestamp;
            $this->setStreamInfo($streamKey, $streamInfo);
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
            'last_activity' => now()->timestamp,
            'options' => $options
        ];
        $this->redis()->setex($clientKey, self::CLIENT_TIMEOUT, json_encode($clientInfo));
    }

    /**
     * Get stream storage directory
     */
    private function getStreamStorageDir(string $streamKey): string
    {
        return 'shared_streams/' . md5($streamKey);
    }

    /**
     * Set stream process PID
     */
    private function setStreamProcess(string $streamKey, int $pid): void
    {
        $pidKey = "stream_pid:{$streamKey}";
        $this->redis()->setex($pidKey, self::SEGMENT_EXPIRY, $pid);
    }

    /**
     * Clean up stream resources
     */
    private function cleanupStream(string $streamKey, bool $removeData = false): void
    {
        if ($removeData) {
            $this->redis()->del($streamKey);
            $bufferKey = self::BUFFER_PREFIX . $streamKey;
            $this->redis()->del($bufferKey);
        }
        
        SharedStream::where('stream_id', $streamKey)->update(['status' => 'stopped']);
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
     * Get next stream segments for a client
     */
    public function getNextStreamSegments(string $streamKey, string $clientId, int &$lastSegment): ?string
    {
        $bufferKey = self::BUFFER_PREFIX . $streamKey;
        $segmentNumbers = $this->redis()->lrange("{$bufferKey}:segments", 0, -1);
        
        if (empty($segmentNumbers)) {
            return null;
        }
        
        $data = '';
        foreach ($segmentNumbers as $segmentNumber) {
            if ($segmentNumber > $lastSegment) {
                $segmentKey = "{$bufferKey}:segment_{$segmentNumber}";
                $segmentData = $this->redis()->get($segmentKey);
                if ($segmentData) {
                    $data .= $segmentData;
                    $lastSegment = $segmentNumber;
                }
            }
        }
        
        return !empty($data) ? $data : null;
    }

    /**
     * Synchronize stream state between Redis and database
     * Called by ManageSharedStreams command
     */
    public function synchronizeState(): void
    {
        try {
            $redis = $this->redis();
            
            // Get all active stream keys from Redis
            $streamKeys = $redis->keys(self::STREAM_PREFIX . '*');
            
            foreach ($streamKeys as $fullKey) {
                $streamKey = str_replace(self::STREAM_PREFIX, '', $fullKey);
                $streamInfo = $this->getStreamInfo($streamKey);
                
                if ($streamInfo) {
                    // Check if process is still running
                    $pid = $streamInfo['pid'] ?? null;
                    $isRunning = false;
                    
                    if ($pid) {
                        $isRunning = $this->isProcessRunning($pid);
                    }
                    
                    // Update database status based on actual process state
                    $status = $isRunning ? 'active' : 'stopped';
                    
                    SharedStream::where('stream_id', $streamKey)->update([
                        'status' => $status,
                        'last_activity' => now()
                    ]);
                    
                    // Clean up if process is dead
                    if (!$isRunning) {
                        $this->cleanupStream($streamKey, true);
                    }
                }
            }
            
            Log::channel('ffmpeg')->debug("Synchronized " . count($streamKeys) . " stream states");
            
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Error synchronizing stream states: " . $e->getMessage());
        }
    }

    /**
     * Check if a process is running by PID
     */
    private function isProcessRunning(int $pid): bool
    {
        try {
            if (function_exists('posix_kill')) {
                return posix_kill($pid, 0);
            }
            
            // Fallback for systems without posix functions
            $result = shell_exec("ps -p $pid");
            return !empty($result) && strpos($result, (string)$pid) !== false;
            
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->warning("Could not check process $pid: " . $e->getMessage());
            return false;
        }
    }
}
