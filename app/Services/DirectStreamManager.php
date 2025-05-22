<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class DirectStreamManager
{
    const MAX_IDLE_TIME = 10; // seconds

    public function __construct()
    {
        // Setup the stream file paths
        $storageDir = Storage::disk('app')->path("direct/stream_pipes");
        File::ensureDirectoryExists($storageDir, 0755);
    }

    /**
     * Get or create a stream for the channel
     */
    public function getOrCreateStream(
        int $channelId,
        string $format,
        array $settings,
        string $streamUrl,
        string $userAgent
    ): string {
        $streamKey = "direct:stream:{$channelId}:{$format}";
        $pipePath = $this->getPipePath($channelId, $format);

        // Create named pipe if it doesn't exist
        if (!file_exists($pipePath)) {
            $this->createNamedPipe($pipePath);
        }

        // Check if ffmpeg is already running
        if (!$this->isProcessRunning($channelId, $format)) {
            $this->startFFmpegProcess($channelId, $format, $settings, $streamUrl, $userAgent, $pipePath);
        }

        // Update last active timestamp
        Redis::set("{$streamKey}:last_active", time());

        return $pipePath;
    }

    /**
     * Get the path to the named pipe for this channel/format
     */
    public function getPipePath(int $channelId, string $format): string
    {
        $extension = $format === 'mp2t' ? 'ts' : $format;
        return Storage::disk('app')
            ->path("direct/stream_pipes/channel_{$channelId}.{$extension}");
    }

    /**
     * Create a named pipe (FIFO) for streaming
     */
    protected function createNamedPipe(string $pipePath): void
    {
        if (file_exists($pipePath)) {
            Log::channel('ffmpeg')->info("Removing existing pipe at: {$pipePath}");
            unlink($pipePath);
        }
        Log::channel('ffmpeg')->info("Creating named pipe at: {$pipePath}");
        $result = posix_mkfifo($pipePath, 0644);
        if (!$result) {
            Log::channel('ffmpeg')->error("Failed to create named pipe: {$pipePath}");
            throw new \Exception("Could not create streaming pipe");
        }
        Log::channel('ffmpeg')->info("Named pipe created successfully at: {$pipePath}");
    }

    /**
     * Start an FFmpeg process for this channel
     */
    public function startFFmpegProcess(
        int $channelId,
        string $format,
        array $settings,
        string $streamUrl,
        string $userAgent,
        string $pipePath
    ): void {
        $streamKey = "direct:stream:{$channelId}:{$format}";
        $ffmpegPath = $settings['ffmpeg_path'] ?? 'jellyfin-ffmpeg';

        // Get codec settings
        $videoCodec = $settings['ffmpeg_codec_video'] ?? 'copy';
        $audioCodec = $settings['ffmpeg_codec_audio'] ?? 'copy';
        $subtitleCodec = $settings['ffmpeg_codec_subtitles'] ?? 'copy';

        // Build FFmpeg output options based on format
        $output = $format === 'mp2t'
            ? "-c:v {$videoCodec} -c:a {$audioCodec} -c:s {$subtitleCodec} -f mpegts"
            : "-c:v {$videoCodec} -c:a {$audioCodec} -bsf:a aac_adtstoasc -c:s {$subtitleCodec} -f mp4 -movflags frag_keyframe+empty_moov+default_base_moof";

        // Build the FFmpeg command
        $cmd = sprintf(
            '%s -y -fflags nobuffer -flags low_delay ' .
                '-user_agent "%s" -referer "MyComputer" ' .
                '-multiple_requests 1 -reconnect_on_network_error 1 ' .
                '-reconnect_on_http_error 5xx,4xx -reconnect_streamed 1 ' .
                '-reconnect_delay_max 5 -noautorotate ' .
                '%s ' . // user args
                '-re -i "%s" ' .
                // Prevent buffer overflow and limit file size
                '-max_muxing_queue_size 1024 ' .
                // Output options
                '%s %s ' .
                // Logging
                '%s',
            $ffmpegPath,
            str_replace('"', '\"', $userAgent), // escape quotes in user agent
            $settings['ffmpeg_additional_args'] ?? '',
            $streamUrl,
            $output,
            $pipePath,
            $settings['ffmpeg_debug'] ? '' : '-hide_banner -nostats -loglevel error'
        );

        // Start FFmpeg in the background
        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];
        $pipes = [];
        $process = proc_open($cmd, $descriptors, $pipes);

        if (!is_resource($process)) {
            Log::channel('ffmpeg')->error("Failed to launch FFmpeg for channel {$channelId}");
            throw new \Exception("Could not start stream process");
        }

        // Close stdin and stdout since we're not using them
        fclose($pipes[0]);
        fclose($pipes[1]);

        // Make stderr non-blocking for logging
        stream_set_blocking($pipes[2], false);

        // Log FFmpeg errors asynchronously
        $stderr = $pipes[2];
        $logger = Log::channel('ffmpeg');

        // Register shutdown function to clean up
        register_shutdown_function(function () use ($stderr, $process, $logger) {
            while (!feof($stderr)) {
                $line = fgets($stderr);
                if ($line !== false) {
                    $logger->error(trim($line));
                }
            }
            fclose($stderr);
            proc_close($process);
        });

        // Get and store the actual process ID
        $status = proc_get_status($process);
        $pid = $status['pid'];

        // Store process info in Redis
        Redis::set("{$streamKey}:pid", $pid);
        Redis::set("{$streamKey}:started_at", time());
        Redis::set("{$streamKey}:pipe", $pipePath);

        Log::channel('ffmpeg')->info("Started FFmpeg direct stream for channel {$channelId} in {$format} format. PID: {$pid}");
    }

    /**
     * Check if the FFmpeg process is running
     */
    public function isProcessRunning(int $channelId, string $format): bool
    {
        $streamKey = "direct:stream:{$channelId}:{$format}";
        $pid = Redis::get("{$streamKey}:pid");

        if (!$pid) {
            return false;
        }

        // Check if the process is still running
        try {
            $result = posix_kill($pid, 0);
            return $result;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Add a viewer to the stream
     */
    public function addViewer(int $channelId, string $format, string $ip): void
    {
        $streamKey = "direct:stream:{$channelId}:{$format}";
        $viewerId = "{$ip}:" . uniqid();

        Redis::sadd("{$streamKey}:viewers", $viewerId);
        Redis::set("{$streamKey}:last_active", time());
    }

    /**
     * Remove a viewer from the stream
     */
    public function removeViewer(int $channelId, string $format, string $viewerId): void
    {
        $streamKey = "direct:stream:{$channelId}:{$format}";
        Redis::srem("{$streamKey}:viewers", $viewerId);
        Redis::set("{$streamKey}:last_active", time());
    }

    /**
     * Get the number of current viewers
     */
    public function getViewerCount(int $channelId, string $format): int
    {
        $streamKey = "direct:stream:{$channelId}:{$format}";
        return Redis::scard("{$streamKey}:viewers") ?: 0;
    }

    /**
     * Stop the stream and clean up resources
     */
    public function stopStream(int $channelId, string $format): bool
    {
        $streamKey = "direct:stream:{$channelId}:{$format}";
        $pid = Redis::get("{$streamKey}:pid");
        $pipePath = Redis::get("{$streamKey}:pipe");

        if (!$pid) {
            return false;
        }

        // Try to stop the FFmpeg process gracefully
        try {
            posix_kill((int)$pid, SIGTERM);

            // Give it a moment to shut down
            sleep(1);

            // If still running, force kill
            if (posix_kill((int)$pid, 0)) {
                posix_kill((int)$pid, SIGKILL);
            }
        } catch (\Exception $e) {
            // Process might already be gone
        }

        // Remove Redis keys
        Redis::del([
            "{$streamKey}:pid",
            "{$streamKey}:started_at",
            "{$streamKey}:pipe",
            "{$streamKey}:viewers",
            "{$streamKey}:last_active"
        ]);

        // Remove the pipe if it exists
        if ($pipePath && file_exists($pipePath)) {
            unlink($pipePath);
        }

        Log::channel('ffmpeg')->info("Stopped direct stream for channel {$channelId} in {$format} format");
        return true;
    }

    /**
     * Clean up inactive streams
     */
    public function cleanupInactiveStreams(): void
    {
        $keys = Redis::keys('direct:stream:*:pid');

        foreach ($keys as $key) {
            // Extract channel ID and format from the key
            preg_match('/direct:stream:(\d+):(.+?):pid/', $key, $matches);

            if (count($matches) >= 3) {
                $channelId = (int)$matches[1];
                $format = $matches[2];
                $streamKey = "direct:stream:{$channelId}:{$format}";

                // Check if there are active viewers
                $viewerCount = $this->getViewerCount($channelId, $format);
                $lastActive = (int)Redis::get("{$streamKey}:last_active") ?: 0;
                $idleTime = time() - $lastActive;

                // If no viewers or idle too long, stop the stream
                if ($viewerCount === 0 && $idleTime > self::MAX_IDLE_TIME) {
                    $this->stopStream($channelId, $format);
                }
            }
        }
    }
}
