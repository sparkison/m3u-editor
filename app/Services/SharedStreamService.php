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

        // Register this client for the stream
        $this->registerClient($streamKey, $clientId, $options);

        if (!$streamInfo || !$this->isStreamActive($streamKey)) {
            // Create new shared stream
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
     * Start continuous buffering process (simplified)
     */
    private function startContinuousBuffering(string $streamKey, $stdout, $stderr, $process): void
    {
        // Set streams to non-blocking mode immediately
        stream_set_blocking($stdout, false);
        stream_set_blocking($stderr, false);
        
        // Start immediate buffering to prevent FFmpeg from hanging
        $this->startImmediateBuffering($streamKey, $stdout, $stderr, $process);
        
        // Use background process to continue buffering (since we can't serialize file handles for jobs)
        Log::channel('ffmpeg')->debug("Stream {$streamKey}: Using background buffer management");
        $this->runBufferManagerBackground($streamKey, $stdout, $stderr, $process);
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
     */
    private function startInitialBuffering(string $streamKey, $stdout, $stderr, $process): void
    {
        $bufferKey = self::BUFFER_PREFIX . $streamKey;
        $segmentNumber = 0;
        $bufferSize = 188 * 1000; // ~188KB chunks
        $maxWait = 10; // Wait up to 10 seconds for data
        $waitTime = 0;

        Log::channel('ffmpeg')->debug("Stream {$streamKey}: Starting initial buffering (waiting for FFmpeg data)");

        // Wait for FFmpeg to start producing data
        while ($waitTime < $maxWait) {
            // Try to read data (non-blocking)
            $data = fread($stdout, $bufferSize);
            if ($data !== false && strlen($data) > 0) {
                $segmentKey = "{$bufferKey}:segment_{$segmentNumber}";
                
                // Store segment in Redis with expiry
                $this->redis()->setex($segmentKey, self::SEGMENT_EXPIRY, $data);
                
                // Update stream segment list
                $this->redis()->lpush("{$bufferKey}:segments", $segmentNumber);
                $this->redis()->ltrim("{$bufferKey}:segments", 0, 30);
                
                $segmentNumber++;
                $this->updateStreamActivity($streamKey);
                
                Log::channel('ffmpeg')->info("Stream {$streamKey}: First data received! Segment {$segmentNumber} buffered (" . strlen($data) . " bytes)");
                
                // Buffer many more segments to get the stream going and drain the pipe
                for ($i = 1; $i < 10; $i++) {
                    $moreData = fread($stdout, $bufferSize);
                    if ($moreData !== false && strlen($moreData) > 0) {
                        $segmentKey = "{$bufferKey}:segment_{$segmentNumber}";
                        $this->redis()->setex($segmentKey, self::SEGMENT_EXPIRY, $moreData);
                        $this->redis()->lpush("{$bufferKey}:segments", $segmentNumber);
                        $this->redis()->ltrim("{$bufferKey}:segments", 0, 30);
                        $segmentNumber++;
                        Log::channel('ffmpeg')->debug("Stream {$streamKey}: Additional segment {$segmentNumber} buffered (" . strlen($moreData) . " bytes)");
                    } else {
                        // No more data available immediately, but we have some data
                        break;
                    }
                }
                break;
            }

            // Check for errors
            $error = fread($stderr, 1024);
            if ($error !== false && strlen($error) > 0) {
                Log::channel('ffmpeg')->error("Stream {$streamKey} FFmpeg error during initial buffering: {$error}");
            }

            // Wait a bit and try again
            usleep(500000); // 500ms
            $waitTime += 0.5;
        }
        
        if ($segmentNumber > 0) {
            Log::channel('ffmpeg')->info("Stream {$streamKey}: Initial buffering completed successfully with {$segmentNumber} segments");
            
            // Update stream status to active since we have data
            $streamInfo = $this->getStreamInfo($streamKey);
            if ($streamInfo) {
                $streamInfo['status'] = 'active';
                $streamInfo['first_data_at'] = time();
                $this->setStreamInfo($streamKey, $streamInfo);
                
                // Also update database status
                SharedStream::where('stream_id', $streamKey)->update([
                    'status' => 'active'
                ]);
                
                Log::channel('ffmpeg')->info("Stream {$streamKey}: Status updated to 'active' - stream is ready for clients");
            }
        } else {
            Log::channel('ffmpeg')->warning("Stream {$streamKey}: No data received after {$maxWait}s, FFmpeg may have failed to start");
            
            // Mark stream as failed since no data was received
            $streamInfo = $this->getStreamInfo($streamKey);
            if ($streamInfo) {
                $streamInfo['status'] = 'error';
                $streamInfo['error_message'] = 'No data received from FFmpeg within timeout period';
                $this->setStreamInfo($streamKey, $streamInfo);
                
                // Also update database status
                SharedStream::where('stream_id', $streamKey)->update([
                    'status' => 'error',
                    'error_message' => 'No data received from FFmpeg within timeout period'
                ]);
                
                Log::channel('ffmpeg')->error("Stream {$streamKey}: Status updated to 'error' - no data received within {$maxWait}s");
            }
        }
    }

    /**
     * Run the buffer manager (reads from FFmpeg, stores in Redis for sharing)
     */
    private function runBufferManager(string $streamKey, $stdout, $stderr, $process): void
    {
        $bufferKey = self::BUFFER_PREFIX . $streamKey;
        $segmentNumber = 0;
        $bufferSize = 188 * 1000; // ~188KB chunks (similar to xTeVe's approach)
        $lastActivity = time();
        $maxInactiveTime = 60; // 60 seconds of no data before giving up

        Log::channel('ffmpeg')->debug("Stream {$streamKey}: Buffer manager starting continuous read loop");

        try {
            while (!feof($stdout) && $this->isStreamActive($streamKey)) {
                $hasData = false;

                // Read data from FFmpeg stdout with improved buffering
                $data = fread($stdout, $bufferSize);
                if ($data !== false && strlen($data) > 0) {
                    // Only store segments that have substantial data to prevent tiny segments
                    if (strlen($data) >= 1024) { // At least 1KB to avoid tiny segments
                        $segmentKey = "{$bufferKey}:segment_{$segmentNumber}";
                        
                        // Store segment in Redis with expiry
                        $this->redis()->setex($segmentKey, self::SEGMENT_EXPIRY, $data);
                        
                        // Update stream segment list
                        $this->redis()->lpush("{$bufferKey}:segments", $segmentNumber);
                        $this->redis()->ltrim("{$bufferKey}:segments", 0, 30); // Keep last 30 segments
                        
                        $segmentNumber++;
                        $hasData = true;
                        $lastActivity = time();
                        
                        // Update stream activity
                        $this->updateStreamActivity($streamKey);
                        
                        // Log progress for performance monitoring
                        if ($segmentNumber <= 10 || $segmentNumber % 50 === 0) {
                            Log::channel('ffmpeg')->debug("Stream {$streamKey}: Buffered segment {$segmentNumber} (" . strlen($data) . " bytes)");
                        }
                    }
                }

                // Check for errors from FFmpeg stderr
                $error = fread($stderr, 1024);
                if ($error !== false && strlen($error) > 0) {
                    Log::channel('ffmpeg')->error("Stream {$streamKey} FFmpeg error: {$error}");
                }

                // Check if process is still running
                $status = proc_get_status($process);
                if (!$status['running']) {
                    Log::channel('ffmpeg')->warning("Stream {$streamKey}: FFmpeg process terminated (exit code: {$status['exitcode']})");
                    break;
                }

                // Adaptive sleep based on data availability
                if (!$hasData) {
                    if (time() - $lastActivity > $maxInactiveTime) {
                        Log::channel('ffmpeg')->warning("Stream {$streamKey}: No data for {$maxInactiveTime}s, ending buffer manager");
                        break;
                    }
                    usleep(50000); // 50ms sleep when no data (reduced from 100ms)
                } else {
                    usleep(5000); // 5ms sleep when actively buffering (reduced from 10ms)
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
     * Get stream data for a client
     */
    public function getStreamData(string $streamKey, string $clientId, int $fromSegment = 0): ?string
    {
        if (!$this->isClientRegistered($streamKey, $clientId)) {
            return null;
        }

        $this->updateClientActivity($streamKey, $clientId);

        $bufferKey = self::BUFFER_PREFIX . $streamKey;
        $segments = $this->redis()->lrange("{$bufferKey}:segments", 0, -1);

        $data = '';
        foreach ($segments as $segmentNum) {
            if ($segmentNum >= $fromSegment) {
                $segmentKey = "{$bufferKey}:segment_{$segmentNum}";
                $segmentData = $this->redis()->get($segmentKey);
                if ($segmentData) {
                    $data .= $segmentData;
                }
            }
        }

        return $data ?: null;
    }

    /**
     * Get HLS playlist for shared stream
     */
    public function getHLSPlaylist(string $streamKey, string $clientId): ?string
    {
        if (!$this->isClientRegistered($streamKey, $clientId)) {
            return null;
        }

        $this->updateClientActivity($streamKey, $clientId);

        $storageDir = $this->getStreamStorageDir($streamKey);
        $playlistPath = Storage::path("{$storageDir}/stream.m3u8");

        if (file_exists($playlistPath)) {
            return file_get_contents($playlistPath);
        }

        return null;
    }

    /**
     * Get HLS segment for shared stream
     */
    public function getHLSSegment(string $streamKey, string $clientId, string $segment): ?string
    {
        if (!$this->isClientRegistered($streamKey, $clientId)) {
            return null;
        }

        $this->updateClientActivity($streamKey, $clientId);

        $storageDir = $this->getStreamStorageDir($streamKey);
        $segmentPath = Storage::path("{$storageDir}/{$segment}");

        if (file_exists($segmentPath)) {
            return file_get_contents($segmentPath);
        }

        return null;
    }

    /**
     * Remove client from stream
     */
    public function removeClient(string $streamKey, string $clientId): void
    {
        $clientKey = self::CLIENT_PREFIX . $streamKey;
        $this->redis()->hdel($clientKey, $clientId);
        
        // Also remove database record
        try {
            SharedStreamClient::where('stream_id', $streamKey)
                             ->where('client_id', $clientId)
                             ->delete();
        } catch (\Exception $e) {
            Log::error("Failed to delete SharedStreamClient record: " . $e->getMessage());
        }
        
        $clientCount = $this->decrementClientCount($streamKey);
        
        Log::channel('ffmpeg')->debug("Removed client {$clientId} from stream {$streamKey}. Remaining clients: {$clientCount}");

        // If no clients left, cleanup stream and decrement playlist count
        if ($clientCount <= 0) {
            // Get stream info to find playlist ID
            $streamInfo = $this->getStreamInfo($streamKey);
            if ($streamInfo && isset($streamInfo['options']['playlist_id'])) {
                $playlistId = $streamInfo['options']['playlist_id'];
                $this->decrementActiveStreams($playlistId);
                Log::channel('ffmpeg')->debug("Decremented active streams for playlist {$playlistId}");
            }
            
            $this->cleanupStream($streamKey);
        }
    }

    /**
     * Build FFmpeg command for HLS streaming
     */
    private function buildHLSCommand(string $ffmpegPath, array $streamInfo, string $storageDir, string $userAgent): string
    {
        $streamUrl = $streamInfo['stream_url'];
        $absoluteStorageDir = Storage::path($storageDir);

        $cmd = escapeshellcmd($ffmpegPath) . ' ';
        $cmd .= '-hide_banner -loglevel error ';
        $cmd .= '-user_agent ' . escapeshellarg($userAgent) . ' ';
        $cmd .= '-i ' . escapeshellarg($streamUrl) . ' ';
        $cmd .= '-c:v copy -c:a copy ';
        $cmd .= '-f hls -hls_time 4 -hls_list_size 15 ';
        $cmd .= '-hls_segment_filename ' . escapeshellarg($absoluteStorageDir . '/segment_%03d.ts') . ' ';
        $cmd .= escapeshellarg($absoluteStorageDir . '/stream.m3u8');

        return $cmd;
    }

    /**
     * Build FFmpeg command for direct streaming
     */
    private function buildDirectCommand(string $ffmpegPath, array $streamInfo, string $userAgent): string
    {
        $streamUrl = $streamInfo['stream_url'];

        $cmd = escapeshellcmd($ffmpegPath) . ' ';
        $cmd .= '-hide_banner -loglevel error ';
        
        // Optimizations for streaming performance and low latency
        $cmd .= '-fflags nobuffer+igndts -flags low_delay ';
        $cmd .= '-avoid_negative_ts disabled -fpsprobesize 0 ';
        $cmd .= '-analyzeduration 1M -probesize 1M -max_delay 500000 ';
        
        // Connection resilience and error handling
        $cmd .= '-err_detect ignore_err -ignore_unknown ';
        $cmd .= '-reconnect 1 -reconnect_streamed 1 -reconnect_delay_max 5 ';
        $cmd .= '-multiple_requests 1 -reconnect_on_network_error 1 ';
        $cmd .= '-reconnect_on_http_error 5xx,4xx ';
        
        // User agent and input
        $cmd .= '-user_agent ' . escapeshellarg($userAgent) . ' ';
        $cmd .= '-referer ' . escapeshellarg("MyComputer") . ' ';
        $cmd .= '-i ' . escapeshellarg($streamUrl) . ' ';
        
        // Output optimization for streaming
        $cmd .= '-c:v copy -c:a copy -avoid_negative_ts make_zero ';
        $cmd .= '-f mpegts -mpegts_copyts 1 -async 1 pipe:1';

        return $cmd;
    }

    /**
     * Monitor HLS stream and manage segments
     */
    private function monitorHLSStream(string $streamKey, SymfonyProcess $process, string $storageDir): void
    {
        // This would typically run in a background job
        // For now, we'll set up basic monitoring via shutdown function
        register_shutdown_function(function () use ($streamKey, $process, $storageDir) {
            // Cleanup when process ends
            if (!$process->isRunning()) {
                $this->cleanupStream($streamKey);
            }
        });
    }

    /**
     * Get next stream segments for client streaming
     * This method is called by SharedStreamController to stream data to clients
     */
    public function getNextStreamSegments(string $streamKey, string $clientId, int &$lastSegment): ?string
    {
        if (!$this->isClientRegistered($streamKey, $clientId)) {
            return null;
        }

        $this->updateClientActivity($streamKey, $clientId);

        try {
            $redis = $this->redis();
            $bufferKey = self::BUFFER_PREFIX . $streamKey;
            $segments = $redis->lrange("{$bufferKey}:segments", 0, -1);
            
            // Get segments that are newer than lastSegment
            $data = '';
            $segmentsRead = 0;
            
            foreach ($segments as $segmentNum) {
                if ($segmentNum > $lastSegment) {
                    $segmentKey = "{$bufferKey}:segment_{$segmentNum}";
                    $segmentData = $redis->get($segmentKey);
                    
                    if ($segmentData) {
                        $data .= $segmentData;
                        $lastSegment = max($lastSegment, $segmentNum);
                        $segmentsRead++;
                        
                        // Limit to a reasonable number of segments per call to prevent memory issues
                        if ($segmentsRead >= 5) {
                            break;
                        }
                    }
                }
            }

            return $data ?: null;
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Error in getNextStreamSegments for {$streamKey}: " . $e->getMessage());
            return null;
        }
    }

    // ...existing code...

    // Helper methods for cache management
    
    private function getStreamKey(string $type, int $modelId, string $streamUrl): string
    {
        return md5("{$type}:{$modelId}:{$streamUrl}");
    }

    private function getStreamInfo(string $streamKey): ?array
    {
        try {
            $redis = $this->redis();
            
            // Try both with and without database prefix
            $key1 = self::CACHE_PREFIX . $streamKey;
            $key2 = config('database.redis.options.prefix', '') . self::CACHE_PREFIX . $streamKey;
            
            $data = $redis->get($key1);
            if (!$data) {
                $data = $redis->get($key2);
            }
            
            // Return null if data is empty or invalid
            if (!$data || empty(trim($data))) {
                return null;
            }
            
            $decoded = json_decode($data, true);
            return $decoded && !empty($decoded) ? $decoded : null;
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Error getting stream info for {$streamKey}: " . $e->getMessage());
            return null;
        }
    }

    public function setStreamInfo(string $streamKey, array $streamInfo): void
    {
        try {
            $redis = $this->redis();
            $redis->setex(self::CACHE_PREFIX . $streamKey, 3600, json_encode($streamInfo));
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Error setting stream info for {$streamKey}: " . $e->getMessage());
        }
    }

    private function isStreamActive(string $streamKey): bool
    {
        try {
            $redis = $this->redis();
            return $redis->exists(self::CACHE_PREFIX . $streamKey);
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Error checking if stream is active for {$streamKey}: " . $e->getMessage());
            return false;
        }
    }

    private function registerClient(string $streamKey, string $clientId, array $options): void
    {
        $clientKey = self::CLIENT_PREFIX . $streamKey;
        $clientData = [
            'id' => $clientId,
            'connected_at' => now()->timestamp,
            'last_activity' => now()->timestamp,
            'options' => $options
        ];
        $this->redis()->hset($clientKey, $clientId, json_encode($clientData));
        $this->redis()->expire($clientKey, 3600);
        
        // Also create database record for dashboard tracking
        try {
            SharedStreamClient::firstOrCreate(
                [
                    'stream_id' => $streamKey,
                    'client_id' => $clientId
                ],
                [
                    'ip_address' => $options['ip'] ?? 'unknown',
                    'user_agent' => $options['user_agent'] ?? null,
                    'connected_at' => now(),
                    'last_activity_at' => now(),
                    'status' => 'connected'
                ]
            );
        } catch (\Exception $e) {
            Log::error("Failed to create SharedStreamClient record: " . $e->getMessage());
        }
    }

    private function isClientRegistered(string $streamKey, string $clientId): bool
    {
        $clientKey = self::CLIENT_PREFIX . $streamKey;
        return $this->redis()->hexists($clientKey, $clientId);
    }

    private function updateClientActivity(string $streamKey, string $clientId): void
    {
        $clientKey = self::CLIENT_PREFIX . $streamKey;
        if ($this->redis()->hexists($clientKey, $clientId)) {
            $clientData = json_decode($this->redis()->hget($clientKey, $clientId), true);
            $clientData['last_activity'] = now()->timestamp;
            $this->redis()->hset($clientKey, $clientId, json_encode($clientData));
            
            // Also update database record
            try {
                SharedStreamClient::where('stream_id', $streamKey)
                                 ->where('client_id', $clientId)
                                 ->update(['last_activity_at' => now()]);
            } catch (\Exception $e) {
                Log::error("Failed to update SharedStreamClient activity: " . $e->getMessage());
            }
        }
    }

    private function updateStreamActivity(string $streamKey): void
    {
        $streamInfo = $this->getStreamInfo($streamKey);
        if ($streamInfo) {
            $streamInfo['last_activity'] = now()->timestamp;
            $this->setStreamInfo($streamKey, $streamInfo);
        }
    }

    private function incrementClientCount(string $streamKey): int
    {
        $clientKey = self::CLIENT_PREFIX . $streamKey;
        return $this->redis()->hlen($clientKey);
    }

    private function decrementClientCount(string $streamKey): int
    {
        $clientKey = self::CLIENT_PREFIX . $streamKey;
        return $this->redis()->hlen($clientKey);
    }

    private function setStreamProcess(string $streamKey, int $pid): void
    {
        $this->redis()->setex("stream_pid:{$streamKey}", 3600, $pid);
    }

    private function getStreamStorageDir(string $streamKey): string
    {
        return "shared_streams/{$streamKey}";
    }

    /**
     * Cleanup a specific stream
     */
    public function cleanupStream(string $streamKey, bool $force = false): bool
    {
        try {
            // Get stream info using the proper method
            $streamInfo = $this->getStreamInfo($streamKey);
            
            if (empty($streamInfo) && !$force) {
                return true; // Already cleaned up
            }

            // Stop the stream process if running
            if (isset($streamInfo['pid']) && $streamInfo['pid']) {
                $this->stopStreamProcess((int)$streamInfo['pid']);
            }

            // Clean up Redis keys with proper prefixes
            Redis::del(self::CACHE_PREFIX . $streamKey);
            Redis::del(self::CLIENT_PREFIX . $streamKey);
            Redis::del(self::BUFFER_PREFIX . $streamKey);
            Redis::del("stream_pid:{$streamKey}");
            
            // Also clean up old format keys for compatibility
            Redis::del("stream:$streamKey");
            Redis::del("stream:$streamKey:clients");
            Redis::del("stream:$streamKey:buffer");
            Redis::del("stream:$streamKey:stats");

            // Clean up buffer directory
            $bufferDir = storage_path("app/stream_buffers/$streamKey");
            if (is_dir($bufferDir)) {
                $this->removeDirectory($bufferDir);
            }

            Log::info("Stream cleaned up", ['stream_key' => $streamKey]);
            
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to cleanup stream: " . $e->getMessage(), [
                'stream_key' => $streamKey
            ]);
            return false;
        }
    }

    /**
     * Update stream statistics
     */
    public function updateStreamStats(string $streamKey): void
    {
        try {
            $streamInfo = Redis::hGetAll("stream:$streamKey");
            
            if (empty($streamInfo)) {
                return;
            }

            $clients = Redis::sMembers("stream:$streamKey:clients");
            $clientCount = count($clients);
            
            $stats = [
                'client_count' => $clientCount,
                'last_updated' => time(),
                'status' => $this->isStreamHealthy($streamKey) ? 'healthy' : 'unhealthy',
                'uptime' => isset($streamInfo['started_at']) ? time() - (int)$streamInfo['started_at'] : 0
            ];

            // Get buffer stats
            $bufferDir = storage_path("app/stream_buffers/$streamKey");
            if (is_dir($bufferDir)) {
                $stats['buffer_size'] = $this->getDirectorySize($bufferDir);
                $stats['segment_count'] = count(glob($bufferDir . '/*.ts'));
            }

            Redis::hMSet("stream:$streamKey:stats", $stats);
            
        } catch (\Exception $e) {
            Log::error("Failed to update stream stats: " . $e->getMessage(), [
                'stream_key' => $streamKey
            ]);
        }
    }

    /**
     * Clean up orphaned Redis keys
     */
    public function cleanupOrphanedKeys(): int
    {
        $cleanedCount = 0;
        
        try {
            // Check both stream patterns
            $patterns = ['stream:*', '*shared_stream:*', '*stream_clients:*', '*active_streams:*'];
            
            foreach ($patterns as $pattern) {
                $keys = Redis::keys($pattern);
                
                foreach ($keys as $key) {
                    // Skip sub-keys for stream pattern
                    if (strpos($pattern, 'stream:') === 0) {
                        if (strpos($key, ':clients') !== false || 
                            strpos($key, ':buffer') !== false || 
                            strpos($key, ':stats') !== false) {
                            continue; // Skip sub-keys, they'll be handled with main stream
                        }
                        
                        $streamInfo = Redis::hGetAll($key);
                        
                        // Check if key is empty or process is not running
                        if (empty($streamInfo)) {
                            Redis::del($key);
                            $cleanedCount++;
                            Log::channel('ffmpeg')->debug("Cleaned up empty Redis key: {$key}");
                        } elseif (isset($streamInfo['pid']) && $streamInfo['pid']) {
                            if (!$this->isProcessRunning((int)$streamInfo['pid'])) {
                                $streamKey = str_replace('stream:', '', $key);
                                $this->cleanupStream($streamKey, true);
                                $cleanedCount++;
                                Log::channel('ffmpeg')->debug("Cleaned up orphaned stream key: {$streamKey}");
                            }
                        }
                    } else {
                        // For other patterns, check if the key has empty data
                        $keyType = Redis::type($key);
                        $isEmpty = false;
                        
                        if ($keyType === 'string') {
                            $data = Redis::get($key);
                            $isEmpty = empty($data) || empty(trim($data));
                        } elseif ($keyType === 'hash') {
                            $data = Redis::hGetAll($key);
                            $isEmpty = empty($data);
                        } elseif ($keyType === 'set') {
                            $data = Redis::sMembers($key);
                            $isEmpty = empty($data);
                        }
                        
                        if ($isEmpty) {
                            Redis::del($key);
                            $cleanedCount++;
                            Log::channel('ffmpeg')->debug("Cleaned up empty Redis key: {$key}");
                        }
                    }
                }
            }
            
        } catch (\Exception $e) {
            Log::error("Failed to cleanup orphaned keys: " . $e->getMessage());
        }
        
        return $cleanedCount;
    }

    /**
     * Clean up temporary files
     */
    public function cleanupTempFiles(int $olderThanSeconds = 3600): int
    {
        $bytesFreed = 0;
        
        try {
            $tempDir = storage_path('app/temp');
            
            if (!is_dir($tempDir)) {
                return $bytesFreed;
            }
            
            $cutoffTime = time() - $olderThanSeconds;
            $files = glob($tempDir . '/*');
            
            foreach ($files as $file) {
                if (is_file($file) && filemtime($file) < $cutoffTime) {
                    $fileSize = filesize($file);
                    if (unlink($file)) {
                        $bytesFreed += $fileSize;
                        Log::channel('ffmpeg')->debug("Cleaned up temp file: " . basename($file) . " (" . $fileSize . " bytes)");
                    }
                }
            }
            
        } catch (\Exception $e) {
            Log::error("Failed to cleanup temp files: " . $e->getMessage());
        }
        
        return $bytesFreed;
    }

    /**
     * Restart a stream
     */
    public function restartStream(string $streamKey): bool
    {
        try {
            $streamInfo = Redis::hGetAll("stream:$streamKey");
            
            if (empty($streamInfo)) {
                Log::warning("Cannot restart stream - not found", ['stream_key' => $streamKey]);
                return false;
            }
            
            // Stop current process
            if (isset($streamInfo['pid']) && $streamInfo['pid']) {
                $this->stopStreamProcess((int)$streamInfo['pid']);
            }
            
            // Start new process
            $sourceUrl = $streamInfo['source_url'] ?? '';
            $format = $streamInfo['format'] ?? 'ts';
            
            if (!$sourceUrl) {
                Log::error("Cannot restart stream - no source URL", ['stream_key' => $streamKey]);
                return false;
            }
            
            return $this->startSharedStream($streamKey, $sourceUrl, $format) !== null;
            
        } catch (\Exception $e) {
            Log::error("Failed to restart stream: " . $e->getMessage(), [
                'stream_key' => $streamKey
            ]);
            return false;
        }
    }

    /**
     * Clean up old buffer segments
     */
    public function cleanupOldBufferSegments(string $streamKey): int
    {
        $cleaned = 0;
        
        try {
            $bufferDir = storage_path("app/stream_buffers/$streamKey");
            
            if (!is_dir($bufferDir)) {
                return 0;
            }
            
            $maxAge = config('proxy.shared_streaming.buffer.segment_retention', 300); // 5 minutes
            $cutoffTime = time() - $maxAge;
            
            $files = glob($bufferDir . '/*.ts');
            
            foreach ($files as $file) {
                if (filemtime($file) < $cutoffTime) {
                    if (unlink($file)) {
                        $cleaned++;
                    }
                }
            }
            
        } catch (\Exception $e) {
            Log::error("Failed to cleanup old buffer segments: " . $e->getMessage(), [
                'stream_key' => $streamKey
            ]);
        }
        
        return $cleaned;
    }

    /**
     * Optimize buffer size based on client count
     */
    public function optimizeBufferSize(string $streamKey, int $clientCount): bool
    {
        try {
            $bufferDir = storage_path("app/stream_buffers/$streamKey");
            
            if (!is_dir($bufferDir)) {
                return false;
            }
            
            // Calculate optimal segment count based on client count
            $baseSegments = config('proxy.shared_streaming.buffer.segments', 10);
            $optimalSegments = max($baseSegments, min($clientCount * 2, 30));
            
            $files = glob($bufferDir . '/*.ts');
            usort($files, function($a, $b) {
                return filemtime($a) - filemtime($b);
            });
            
            // Remove oldest segments if we have too many
            $currentCount = count($files);
            if ($currentCount > $optimalSegments) {
                $toRemove = $currentCount - $optimalSegments;
                for ($i = 0; $i < $toRemove; $i++) {
                    unlink($files[$i]);
                }
            }
            
            return true;
            
        } catch (\Exception $e) {
            Log::error("Failed to optimize buffer size: " . $e->getMessage(), [
                'stream_key' => $streamKey
            ]);
            return false;
        }
    }

    /**
     * Get disk usage for a stream's buffer
     */
    public function getStreamBufferDiskUsage(string $streamKey): int
    {
        $bufferDir = storage_path("app/stream_buffers/$streamKey");
        return $this->getDirectorySize($bufferDir);
    }

    /**
     * Trim buffer to specified size
     */
    public function trimBufferToSize(string $streamKey, int $maxSizeBytes): int
    {
        $freed = 0;
        
        try {
            $bufferDir = storage_path("app/stream_buffers/$streamKey");
            
            if (!is_dir($bufferDir)) {
                return 0;
            }
            
            $files = glob($bufferDir . '/*.ts');
            
            // Sort by modification time (oldest first)
            usort($files, function($a, $b) {
                return filemtime($a) - filemtime($b);
            });
            
            $currentSize = $this->getDirectorySize($bufferDir);
            
            foreach ($files as $file) {
                if ($currentSize <= $maxSizeBytes) {
                    break;
                }
                
                $fileSize = filesize($file);
                if (unlink($file)) {
                    $freed += $fileSize;
                    $currentSize -= $fileSize;
                }
            }
            
        } catch (\Exception $e) {
            Log::error("Failed to trim buffer: " . $e->getMessage(), [
                'stream_key' => $streamKey
            ]);
        }
        
        return $freed;
    }

    /**
     * Find orphaned buffer directories
     */
    public function findOrphanedBufferDirectories(): array
    {
        $orphaned = [];
        
        try {
            $bufferBaseDir = storage_path('app/stream_buffers');
            
            if (!is_dir($bufferBaseDir)) {
                return $orphaned;
            }
            
            $dirs = glob($bufferBaseDir . '/*', GLOB_ONLYDIR);
            
            foreach ($dirs as $dir) {
                $streamKey = basename($dir);
                
                // Check if stream exists in Redis
                if (!Redis::exists("stream:$streamKey")) {
                    $orphaned[] = $dir;
                }
            }
            
        } catch (\Exception $e) {
            Log::error("Failed to find orphaned directories: " . $e->getMessage());
        }
        
        return $orphaned;
    }

    /**
     * Get directory size in bytes
     */
    public function getDirectorySize(string $path): int
    {
        if (!is_dir($path)) {
            return 0;
        }
        
        $size = 0;
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($files as $file) {
            $size += $file->getSize();
        }
        
        return $size;
    }

    /**
     * Remove directory and all contents
     */
    public function removeDirectory(string $path): bool
    {
        if (!is_dir($path)) {
            return true;
        }
        
        try {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            
            foreach ($files as $file) {
                if ($file->isDir()) {
                    rmdir($file->getRealPath());
                } else {
                    unlink($file->getRealPath());
                }
            }
            
            return rmdir($path);
            
        } catch (\Exception $e) {
            Log::error("Failed to remove directory: " . $e->getMessage(), [
                'path' => $path
            ]);
            return false;
        }
    }

    /**
     * Get total buffer disk usage across all streams
     */
    public function getTotalBufferDiskUsage(): int
    {
        $bufferBaseDir = storage_path('app/stream_buffers');
        return $this->getDirectorySize($bufferBaseDir);
    }

    /**
     * Trim oldest buffers to reach target size
     */
    public function trimOldestBuffers(int $targetSizeBytes): int
    {
        $freed = 0;
        
        try {
            $bufferBaseDir = storage_path('app/stream_buffers');
            
            if (!is_dir($bufferBaseDir)) {
                return 0;
            }
            
            $dirs = glob($bufferBaseDir . '/*', GLOB_ONLYDIR);
            
            // Sort by modification time (oldest first)
            usort($dirs, function($a, $b) {
                return filemtime($a) - filemtime($b);
            });
            
            $currentTotal = $this->getTotalBufferDiskUsage();
            
            foreach ($dirs as $dir) {
                if ($currentTotal <= $targetSizeBytes) {
                    break;
                }
                
                $dirSize = $this->getDirectorySize($dir);
                $streamKey = basename($dir);
                
                // Try to trim this stream's buffer first
                $streamFreed = $this->trimBufferToSize($streamKey, $dirSize / 2);
                $freed += $streamFreed;
                $currentTotal -= $streamFreed;
                
                // If still over target, remove entire buffer
                if ($currentTotal > $targetSizeBytes) {
                    if ($this->removeDirectory($dir)) {
                        $freed += $dirSize - $streamFreed;
                        $currentTotal -= ($dirSize - $streamFreed);
                    }
                }
            }
            
        } catch (\Exception $e) {
            Log::error("Failed to trim oldest buffers: " . $e->getMessage());
        }
        
        return $freed;
    }

    /**
     * Check if a process is running
     */
    private function isProcessRunning(int $pid): bool
    {
        try {
            return posix_kill($pid, 0);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Stop a stream process
     */
    private function stopStreamProcess(int $pid): bool
    {
        try {
            // Try graceful shutdown first
            if (posix_kill($pid, SIGTERM)) {
                // Wait a bit for graceful shutdown
                $attempts = 0;
                while ($attempts < 30 && posix_kill($pid, 0)) {
                    usleep(100000); // 100ms
                    $attempts++;
                }
                
                // Force kill if still running
                if (posix_kill($pid, 0)) {
                    posix_kill($pid, SIGKILL);
                    Log::warning("Force killed stream process {$pid}");
                }
                
                return true;
            }
            return false;
        } catch (\Exception $e) {
            Log::error("Failed to stop process {$pid}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if a stream is healthy
     */
    private function isStreamHealthy(string $streamKey): bool
    {
        try {
            $streamInfo = Redis::hGetAll("stream:$streamKey");
            
            if (empty($streamInfo)) {
                return false;
            }
            
            // Check if process is running
            if (isset($streamInfo['pid']) && $streamInfo['pid']) {
                if (!$this->isProcessRunning((int)$streamInfo['pid'])) {
                    return false;
                }
            }
            
            // Check last activity
            $lastActivity = $streamInfo['last_activity'] ?? 0;
            $now = time();
            $timeout = config('proxy.shared_streaming.monitoring.stream_timeout', 300);
            
            return ($now - $lastActivity) < $timeout;
            
        } catch (\Exception $e) {
            Log::error("Failed to check stream health: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Start a shared stream (used by restart functionality)
     */
    private function startSharedStream(string $streamKey, string $sourceUrl, string $format): ?string
    {
        try {
            // This is a simplified version - in reality you'd want to use the full createSharedStream logic
            $streamInfo = [
                'stream_key' => $streamKey,
                'stream_url' => $sourceUrl,
                'format' => $format,
                'status' => 'starting',
                'created_at' => time(),
                'last_activity' => time()
            ];
            
            $this->setStreamInfo($streamKey, $streamInfo);
            $this->startStreamingProcess($streamKey, $streamInfo);
            
            return $streamKey;
            
        } catch (\Exception $e) {
            Log::error("Failed to start shared stream: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get the number of clients connected to a stream
     * 
     * @param string $streamKey
     * @return int Number of clients
     */
    public function getClientCount(string $streamKey): int
    {
        try {
            // Get count from Redis
            $clientKey = self::CLIENT_PREFIX . $streamKey;
            $redisClients = Redis::hgetall($clientKey);
            
            // Also get count from database for accuracy
            $dbClientCount = SharedStreamClient::where('stream_id', $streamKey)
                                              ->where('status', 'connected')
                                              ->where('last_activity_at', '>=', now()->subMinutes(2))
                                              ->count();
            
            // Use the higher count (Redis might have stale data)
            $redisCount = count($redisClients);
            $finalCount = max($redisCount, $dbClientCount);
            
            Log::channel('ffmpeg')->debug("Client count for stream {$streamKey}: Redis={$redisCount}, DB={$dbClientCount}, Final={$finalCount}");
            
            return $finalCount;
            
        } catch (\Exception $e) {
            Log::error("Failed to get client count for stream {$streamKey}: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Check if a client is still active
     * 
     * @param string $clientId
     * @return bool
     */
    private function isClientActive(string $clientId): bool
    {
        try {
            $lastSeen = Redis::get("client_last_seen:{$clientId}");
            if (!$lastSeen) {
                return false;
            }
            
            $timeout = config('proxy.shared_streaming.monitoring.client_timeout', 30);
            return (time() - $lastSeen) < $timeout;
            
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get all active streams from Redis cache
     * 
     * @return array Array of stream information
     */
    public function getAllActiveStreams(): array
    {
        try {
            // Try both patterns - with and without database prefix
            $pattern1 = self::CACHE_PREFIX . '*';
            $pattern2 = '*' . self::CACHE_PREFIX . '*';
            
            $keys1 = Redis::keys($pattern1);
            $keys2 = Redis::keys($pattern2);
            $keys = array_merge($keys1, $keys2);
            
            $streams = [];
            
            foreach ($keys as $key) {
                // Handle both prefixed and non-prefixed keys
                if (strpos($key, self::CACHE_PREFIX) !== false) {
                    // Extract stream key from the Redis key
                    $streamKey = str_replace([
                        config('database.redis.options.prefix', ''),
                        self::CACHE_PREFIX
                    ], '', $key);
                    
                    $streamInfo = $this->getStreamInfo($streamKey);
                    
                    // Only include streams with valid data
                    if ($streamInfo && !empty($streamInfo) && isset($streamInfo['status'])) {
                        $streamInfo['stream_key'] = $streamKey;
                        $streamInfo['client_count'] = $this->getClientCount($streamKey);
                        $streamInfo['uptime'] = time() - ($streamInfo['created_at'] ?? time());
                        $streamInfo['last_activity'] = $streamInfo['last_activity'] ?? time();
                        
                        $streams[$streamKey] = [
                            'stream_info' => $streamInfo,
                            'client_count' => $streamInfo['client_count'],
                            'uptime' => $streamInfo['uptime'],
                            'last_activity' => $streamInfo['last_activity']
                        ];
                    }
                }
            }
            
            return $streams;
            
        } catch (\Exception $e) {
            Log::error("Failed to get all active streams: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Create a shared stream (simplified interface for testing)
     * 
     * @param string $sourceUrl
     * @param string $format
     * @return string Stream ID
     */
    public function createSharedStream(string $sourceUrl, string $format): string
    {
        $streamKey = $this->getStreamKey('test', 0, $sourceUrl);
        
        $streamInfo = $this->createSharedStreamInternal(
            $streamKey,
            'test',
            0,
            $sourceUrl,
            'Test Stream',
            $format,
            []
        );
        
        return $streamInfo['stream_key'];
    }

    /**
     * Join a stream (create if not exists)
     * 
     * @param string $sourceUrl
     * @param string $format
     * @param string $ipAddress
     * @return array
     */
    public function joinStream(string $sourceUrl, string $format, string $ipAddress): array
    {
        $streamKey = $this->getStreamKey('test', 0, $sourceUrl);
        
        // Check both Redis and database for existing stream
        $existingStream = $this->getStreamInfo($streamKey);
        $dbStream = SharedStream::where('stream_id', $streamKey)->first();
        
        // Accept streams that are 'starting' or 'active' as joinable
        $isExistingJoinable = ($existingStream && in_array($existingStream['status'], ['starting', 'active'])) || 
                              ($dbStream && in_array($dbStream->status, ['starting', 'active']));
        
        if ($isExistingJoinable) {
            // Join existing stream
            return [
                'stream_id' => $streamKey,
                'joined_existing' => true
            ];
        } else {
            // Create new stream
            $streamId = $this->createSharedStream($sourceUrl, $format);
            return [
                'stream_id' => $streamId,
                'joined_existing' => false
            ];
        }
    }

    /**
     * Stop a stream
     * 
     * @param string $streamId
     * @return bool
     */
    public function stopStream(string $streamId): bool
    {
        try {
            $streamInfo = $this->getStreamInfo($streamId);
            if (!$streamInfo) {
                return false;
            }
            
            // Update status to stopped in Redis
            $streamInfo['status'] = 'stopped';
            $streamInfo['stopped_at'] = time();
            $this->setStreamInfo($streamId, $streamInfo);
            
            // Update status in database
            SharedStream::where('stream_id', $streamId)->update([
                'status' => 'stopped',
                'stopped_at' => now()
            ]);
            
            // Clean up the stream
            $this->cleanupStream($streamId, true);
            
            return true;
            
        } catch (\Exception $e) {
            Log::error("Failed to stop stream {$streamId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get stream URL for accessing the stream
     * 
     * @param string $streamId
     * @param string $format
     * @return string
     */
    public function getStreamUrl(string $streamId, string $format): string
    {
        if ($format === 'hls') {
            return route('shared.stream.hls', ['streamKey' => $streamId]);
        } else {
            return route('shared.stream.direct', ['streamKey' => $streamId]);
        }
    }

    /**
     * Clean up inactive streams (streams with no clients or that are stale)
     */
    public function cleanupInactiveStreams(): array
    {
        $cleanedStreams = 0;
        $cleanedClients = 0;
        
        try {
            $activeStreams = $this->getAllActiveStreams();
            
            foreach ($activeStreams as $streamKey => $streamData) {
                $streamInfo = $streamData['stream_info'];
                $clientCount = $streamData['client_count'];
                $lastActivity = $streamData['last_activity'] ?? time();
                
                // Count existing clients before cleanup
                $cleanedClients += $clientCount;
                
                // Check if stream is inactive (no clients and inactive for more than 5 minutes)
                $isInactive = $clientCount === 0 && (time() - $lastActivity) > 300;
                
                // Check if stream is stale (running for more than 4 hours with no recent activity)
                $isStale = isset($streamData['uptime']) && 
                          $streamData['uptime'] > 14400 && 
                          (time() - $lastActivity) > 1800; // 30 minutes
                
                if ($isInactive || $isStale) {
                    $reason = $isInactive ? 'no clients' : 'stale/inactive';
                    Log::channel('ffmpeg')->info("CleanupInactiveStreams: Cleaning up stream {$streamKey} ({$reason})");
                    
                    $success = $this->cleanupStream($streamKey, true);
                    
                    if ($success) {
                        $cleanedStreams++;
                    }
                }
            }
            
            // Also clean up orphaned keys and temp files
            $orphanedKeys = $this->cleanupOrphanedKeys();
            $tempFiles = $this->cleanupTempFiles();
            
            Log::channel('ffmpeg')->info("CleanupInactiveStreams: Completed - Cleaned {$cleanedStreams} streams, {$cleanedClients} clients, {$orphanedKeys} orphaned keys, cleaned " . round($tempFiles / 1024 / 1024, 2) . "MB temp files");
            
            return [
                'cleaned_streams' => $cleanedStreams,
                'cleaned_clients' => $cleanedClients,
                'orphaned_keys' => $orphanedKeys,
                'temp_files_freed' => $tempFiles
            ];
            
        } catch (\Exception $e) {
            Log::error("Failed to cleanup inactive streams: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get statistics for a specific stream
     */
    public function getStreamStats(string $streamKey): ?array
    {
        try {
            $streamInfo = $this->getStreamInfo($streamKey);
            if (!$streamInfo) {
                return null;
            }

            // Get client information
            $clients = [];
            $clientData = Redis::hGetAll("stream:{$streamKey}:clients");
            
            foreach ($clientData as $clientId => $clientInfo) {
                $info = json_decode($clientInfo, true);
                if ($info) {
                    $clients[] = [
                        'id' => $clientId,
                        'connected_at' => $info['connected_at'] ?? null,
                        'last_activity' => $info['last_activity'] ?? null,
                        'user_agent' => $info['user_agent'] ?? null,
                        'ip_address' => $info['ip_address'] ?? null
                    ];
                }
            }

            return [
                'stream_key' => $streamKey,
                'status' => $streamInfo['status'] ?? 'unknown',
                'client_count' => count($clients),
                'clients' => $clients,
                'created_at' => $streamInfo['created_at'] ?? null,
                'last_activity' => $streamInfo['last_activity'] ?? null,
                'stream_url' => $streamInfo['stream_url'] ?? null,
                'format' => $streamInfo['format'] ?? null,
                'title' => $streamInfo['title'] ?? null
            ];
        } catch (\Exception $e) {
            Log::error("Failed to get stream stats for {$streamKey}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Synchronize database and Redis state for shared streams
     * This ensures consistency between what's stored in the database and Redis
     */
    public function synchronizeState(): array
    {
        $stats = [
            'db_updated' => 0,
            'redis_cleaned' => 0,
            'inconsistencies_fixed' => 0
        ];
        
        try {
            // Get all Redis keys for shared streams (handle prefixed keys)
            $pattern1 = self::CACHE_PREFIX . '*';
            $pattern2 = '*' . self::CACHE_PREFIX . '*';
            
            $keys1 = Redis::keys($pattern1);
            $keys2 = Redis::keys($pattern2);
            $redisKeys = array_merge($keys1, $keys2);
            
            $activeRedisStreams = [];
            
            // Check Redis streams and their validity
            foreach ($redisKeys as $key) {
                $streamKey = str_replace([
                    config('database.redis.options.prefix', ''),
                    self::CACHE_PREFIX
                ], '', $key);
                
                $streamInfo = $this->getStreamInfo($streamKey);
                if ($streamInfo) {
                    $activeRedisStreams[] = $streamKey;
                    
                    // Check if stream process is actually running
                    $isProcessRunning = false;
                    if (isset($streamInfo['pid']) && $streamInfo['pid']) {
                        $isProcessRunning = $this->isProcessRunning((int)$streamInfo['pid']);
                    }
                    
                    // Update database status based on Redis state
                    $dbStream = SharedStream::where('stream_id', $streamKey)->first();
                    if ($dbStream) {
                        $newStatus = $isProcessRunning ? 'active' : 'stopped';
                        if ($dbStream->status !== $newStatus) {
                            $dbStream->update([
                                'status' => $newStatus,
                                'stopped_at' => $newStatus === 'stopped' ? now() : null
                            ]);
                            $stats['db_updated']++;
                        }
                    }
                }
            }
            
            // Clean up database records that don't have corresponding Redis entries
            $dbStreams = SharedStream::whereIn('status', ['starting', 'active'])->get();
            foreach ($dbStreams as $dbStream) {
                if (!in_array($dbStream->stream_id, $activeRedisStreams)) {
                    // No Redis entry - mark as stopped
                    $dbStream->update([
                        'status' => 'stopped', 
                        'stopped_at' => now()
                    ]);
                    $stats['inconsistencies_fixed']++;
                }
            }
            
            // Clean up Redis entries for stopped processes
            foreach ($redisKeys as $key) {
                $streamKey = str_replace([
                    config('database.redis.options.prefix', ''),
                    self::CACHE_PREFIX
                ], '', $key);
                
                $streamInfo = $this->getStreamInfo($streamKey);
                if ($streamInfo && isset($streamInfo['pid'])) {
                    if (!$this->isProcessRunning((int)$streamInfo['pid'])) {
                        $this->cleanupStream($streamKey, true);
                        $stats['redis_cleaned']++;
                    }
                }
            }
            
            Log::channel('ffmpeg')->info("SharedStreamService: State synchronization completed", $stats);
            
        } catch (\Exception $e) {
            Log::error("Failed to synchronize shared stream state: " . $e->getMessage());
        }
        
        return $stats;
    }

}
