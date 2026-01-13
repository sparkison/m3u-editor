<?php

namespace App\Services;

use App\Models\MediaServerIntegration;
use App\Models\Network;
use App\Models\NetworkProgramme;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * NetworkBroadcastService - Manages continuous HLS broadcasting for Networks.
 *
 * This service handles:
 * 1. Starting FFmpeg processes to create HLS segments
 * 2. Monitoring running broadcasts
 * 3. Gracefully stopping broadcasts
 * 4. Building FFmpeg commands with correct seek positions
 */
class NetworkBroadcastService
{
    /**
     * Start broadcasting for a network.
     *
     * @return bool True if broadcast started successfully
     */
    public function start(Network $network): bool
    {
        if (! $network->broadcast_enabled) {
            Log::warning('Cannot start broadcast: broadcast not enabled', [
                'network_id' => $network->id,
                'network_name' => $network->name,
            ]);

            return false;
        }

        // Check if already broadcasting
        if ($this->isProcessRunning($network)) {
            Log::info('Broadcast already running', [
                'network_id' => $network->id,
                'pid' => $network->broadcast_pid,
            ]);

            return true;
        }

        // Create storage directory
        $hlsPath = $network->getHlsStoragePath();
        File::ensureDirectoryExists($hlsPath);

        // Get current programme
        $programme = $network->getCurrentProgramme();
        if (! $programme) {
            Log::warning('No current programme to broadcast', [
                'network_id' => $network->id,
            ]);

            return false;
        }

        // Build and start FFmpeg process
        $command = $this->buildFfmpegCommand($network, $programme);
        if (! $command) {
            Log::error('Failed to build FFmpeg command', [
                'network_id' => $network->id,
            ]);

            return false;
        }

        return $this->executeCommand($network, $command);
    }

    /**
     * Stop broadcasting for a network.
     */
    public function stop(Network $network): bool
    {
        $pid = $network->broadcast_pid;

        if (! $pid) {
            Log::info('No broadcast running to stop', [
                'network_id' => $network->id,
            ]);

            $network->update([
                'broadcast_started_at' => null,
                'broadcast_pid' => null,
            ]);

            return true;
        }

        // Try graceful termination first (SIGTERM)
        if ($this->signalProcess($pid, SIGTERM)) {
            // Wait a moment for graceful shutdown
            usleep(500000); // 500ms

            // Check if process is still running
            if (! $this->isProcessRunning($network)) {
                Log::info('Broadcast stopped gracefully', [
                    'network_id' => $network->id,
                    'pid' => $pid,
                ]);

                $network->update([
                    'broadcast_started_at' => null,
                    'broadcast_pid' => null,
                ]);

                // Emit a structured metric/log for broadcast stop
                Log::info('HLS_METRIC: broadcast_stopped', [
                    'network_id' => $network->id,
                    'uuid' => $network->uuid,
                    'old_pid' => $pid,
                ]);

                return true;
            }

            // Force kill if still running
            $this->signalProcess($pid, SIGKILL);
        }

        Log::info('Broadcast force-stopped', [
            'network_id' => $network->id,
            'pid' => $pid,
        ]);

        $network->update([
            'broadcast_started_at' => null,
            'broadcast_pid' => null,
        ]);

        return true;
    }

    /**
     * Check if a network's broadcast process is running.
     */
    public function isProcessRunning(Network $network): bool
    {
        $pid = $network->broadcast_pid;

        if (! $pid) {
            return false;
        }

        // Check if process exists and is FFmpeg
        if (! file_exists("/proc/{$pid}")) {
            return false;
        }

        // Verify it's our FFmpeg process by checking cmdline
        $cmdline = @file_get_contents("/proc/{$pid}/cmdline");

        return $cmdline && str_contains($cmdline, 'ffmpeg');
    }

    /**
     * Build the FFmpeg command for a programme.
     *
     * @return array|null Command array or null on failure
     */
    public function buildFfmpegCommand(Network $network, NetworkProgramme $programme): ?array
    {
        $hlsPath = $network->getHlsStoragePath();
        $seekPosition = $network->getCurrentSeekPosition();
        $remainingDuration = $network->getCurrentRemainingDuration();

        // Get stream URL with seek position built-in (media server handles seeking)
        $streamUrl = $this->getStreamUrl($network, $programme, $seekPosition);
        if (! $streamUrl) {
            return null;
        }

        // Base FFmpeg command
        $command = ['ffmpeg', '-y'];

        // Real-time pacing: -re flag makes FFmpeg read input at native framerate
        // This prevents FFmpeg from processing content at 70x+ speed when stream-copying
        // Critical for live broadcasting to maintain real-time playback
        $command[] = '-re';

        // Input URL with reconnect options for reliability
        $command = array_merge($command, [
            '-reconnect', '1',
            '-reconnect_streamed', '1',
            '-reconnect_delay_max', '10',
            '-i', $streamUrl,
        ]);

        // Duration limit (time remaining in current programme)
        // This ensures we stop at the scheduled end time and transition to next programme
        if ($remainingDuration > 0) {
            $command[] = '-t';
            $command[] = (string) $remainingDuration;
        }

        // Map only video and audio streams (exclude subtitles which can cause HLS issues)
        $command = array_merge($command, [
            '-map', '0:v:0',  // First video stream
            '-map', '0:a:0',  // First audio stream
        ]);

        // Output options - check if we should transcode or copy
        if ($network->transcode_on_server) {
            // Media server is transcoding - we just copy the streams
            $command[] = '-c:v';
            $command[] = 'copy';
            $command[] = '-c:a';
            $command[] = 'copy';
        } else {
            // We need to transcode
            $command = array_merge($command, $this->getTranscodeOptions($network));
        }

        // HLS output options
        // hls_list_size: Number of segments to keep in playlist (20 = ~2 min buffer at 6s segments)
        // delete_segments: Remove old segments to save disk space
        // append_list: Continue segment numbering across restarts
        // program_date_time: Add timestamps for DVR-like seeking
        $command = array_merge($command, [
            '-f', 'hls',
            '-hls_time', (string) ($network->segment_duration ?? 6),
            '-hls_list_size', (string) ($network->hls_list_size ?? 20),
            '-hls_flags', 'delete_segments+append_list+program_date_time',
            '-hls_segment_filename', "{$hlsPath}/live%06d.ts",
            "{$hlsPath}/live.m3u8",
        ]);

        return $command;
    }

    /**
     * Get transcode options for FFmpeg.
     *
     * @return array FFmpeg transcode arguments
     */
    protected function getTranscodeOptions(Network $network): array
    {
        $options = [];

        // Video encoding
        $options[] = '-c:v';
        $options[] = 'libx264';
        $options[] = '-preset';
        $options[] = 'veryfast';

        // Video bitrate
        if ($network->video_bitrate) {
            $options[] = '-b:v';
            $options[] = "{$network->video_bitrate}k";
        }

        // Video resolution
        if ($network->video_resolution) {
            $options[] = '-s';
            $options[] = $network->video_resolution;
        }

        // Audio encoding
        $options[] = '-c:a';
        $options[] = 'aac';
        $options[] = '-b:a';
        $options[] = ($network->audio_bitrate ?? 192).'k';

        return $options;
    }

    /**
     * Get the stream URL for the current programme content.
     *
     * @param  int  $seekSeconds  Seek position in seconds (0 = start)
     */
    protected function getStreamUrl(Network $network, NetworkProgramme $programme, int $seekSeconds = 0): ?string
    {
        $content = $programme->contentable;
        if (! $content) {
            return null;
        }

        // Get media server integration
        $integration = $network->mediaServerIntegration;

        if (! $integration) {
            $integration = $this->getIntegrationFromContent($content);
        }

        if (! $integration) {
            Log::error('No media server integration found', [
                'network_id' => $network->id,
                'programme_id' => $programme->id,
            ]);

            return null;
        }

        // Get item ID
        $itemId = $this->getMediaServerItemId($content);
        if (! $itemId) {
            Log::error('No media server item ID found', [
                'network_id' => $network->id,
                'content_type' => get_class($content),
                'content_id' => $content->id,
            ]);

            return null;
        }

        // Build the stream URL with media server seeking
        $url = "{$integration->base_url}/Videos/{$itemId}/stream.ts";
        $params = [
            'static' => 'true',
            'api_key' => $integration->api_key,
        ];

        // Use media server's native seeking if we need to seek
        if ($seekSeconds > 0) {
            // Jellyfin/Emby use ticks (100-nanosecond intervals)
            $params['StartTimeTicks'] = $seekSeconds * 10_000_000;
        }

        return $url.'?'.http_build_query($params);
    }

    /**
     * Get the media server item ID from content.
     */
    protected function getMediaServerItemId($content): ?string
    {
        // First priority: Check info array for media server ID
        // This is the most reliable for media server content
        if (isset($content->info['media_server_id'])) {
            return (string) $content->info['media_server_id'];
        }

        // Check for source_episode_id (for Episodes from Xtream providers)
        if (isset($content->source_episode_id) && $content->source_episode_id) {
            return (string) $content->source_episode_id;
        }

        // Check for source_channel_id (for Channels/VOD from Xtream providers)
        if (isset($content->source_channel_id) && $content->source_channel_id) {
            return (string) $content->source_channel_id;
        }

        // For channels that might store it differently (VOD movie data)
        if (isset($content->movie_data['movie_data']['id'])) {
            return (string) $content->movie_data['movie_data']['id'];
        }

        return null;
    }

    /**
     * Get media server integration from content.
     */
    protected function getIntegrationFromContent($content): ?MediaServerIntegration
    {
        // Try to extract from cover URL
        $coverUrl = $content->info['cover_big'] ?? $content->info['movie_image'] ?? null;
        if ($coverUrl && preg_match('#/media-server/(\d+)/#', $coverUrl, $matches)) {
            return MediaServerIntegration::find((int) $matches[1]);
        }

        // Try playlist's integration
        if (isset($content->playlist_id) && $content->playlist) {
            if ($content->playlist->media_server_integration_id) {
                return MediaServerIntegration::find($content->playlist->media_server_integration_id);
            }
        }

        return null;
    }

    /**
     * Execute the FFmpeg command as a background process.
     */
    protected function executeCommand(Network $network, array $command): bool
    {
        $commandString = implode(' ', array_map('escapeshellarg', $command));

        Log::info('Starting broadcast FFmpeg process', [
            'network_id' => $network->id,
            'command' => $commandString,
        ]);

        // Create output log file
        $logFile = $network->getHlsStoragePath().'/ffmpeg.log';

        // Start process in background using nohup
        $fullCommand = "nohup {$commandString} >> ".escapeshellarg($logFile)." 2>&1 & echo $!";

        $output = [];
        $returnCode = 0;
        exec($fullCommand, $output, $returnCode);

        if ($returnCode !== 0 || empty($output[0])) {
            Log::error('Failed to start FFmpeg process', [
                'network_id' => $network->id,
                'return_code' => $returnCode,
                'output' => $output,
            ]);

            return false;
        }

        $pid = (int) $output[0];

        // Wait a moment and verify process is still running
        usleep(500000); // 500ms

        if (! file_exists("/proc/{$pid}")) {
            Log::error('FFmpeg process exited immediately', [
                'network_id' => $network->id,
                'pid' => $pid,
                'log' => @file_get_contents($logFile),
            ]);

            return false;
        }

        // Update network with broadcast info
        $network->update([
            'broadcast_started_at' => Carbon::now(),
            'broadcast_pid' => $pid,
        ]);

        Log::info('Broadcast started successfully', [
            'network_id' => $network->id,
            'pid' => $pid,
        ]);

        // Emit a structured metric/log for broadcasting start
        Log::info('HLS_METRIC: broadcast_started', [
            'network_id' => $network->id,
            'uuid' => $network->uuid,
            'pid' => $pid,
        ]);

        return true;
    }

    /**
     * Send a signal to a process.
     */
    protected function signalProcess(int $pid, int $signal): bool
    {
        if (! file_exists("/proc/{$pid}")) {
            return false;
        }

        return posix_kill($pid, $signal);
    }

    /**
     * Clean up old HLS segments for a network.
     */
    public function cleanupSegments(Network $network): int
    {
        $hlsPath = $network->getHlsStoragePath();

        if (! File::isDirectory($hlsPath)) {
            return 0;
        }

        $deleted = 0;
        $files = File::glob("{$hlsPath}/*.ts");

        // Keep segments newer than 2 minutes
        $threshold = Carbon::now()->subMinutes(2);

        foreach ($files as $file) {
            $mtime = Carbon::createFromTimestamp(File::lastModified($file));
            if ($mtime->lt($threshold)) {
                File::delete($file);
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Get status information for a network's broadcast.
     */
    public function getStatus(Network $network): array
    {
        $isRunning = $this->isProcessRunning($network);

        $status = [
            'enabled' => $network->broadcast_enabled,
            'running' => $isRunning,
            'pid' => $network->broadcast_pid,
            'started_at' => $network->broadcast_started_at?->toIso8601String(),
            'hls_url' => $network->hls_url,
        ];

        if ($isRunning) {
            $programme = $network->getCurrentProgramme();
            if ($programme) {
                $status['current_programme'] = [
                    'title' => $programme->title,
                    'start_time' => $programme->start_time->toIso8601String(),
                    'end_time' => $programme->end_time->toIso8601String(),
                    'elapsed_seconds' => $programme->getCurrentOffsetSeconds(),
                    'remaining_seconds' => $network->getCurrentRemainingDuration(),
                ];
            }

            // Check for HLS files
            $hlsPath = $network->getHlsStoragePath();
            if (File::exists("{$hlsPath}/live.m3u8")) {
                $status['playlist_exists'] = true;
                $status['segment_count'] = count(File::glob("{$hlsPath}/*.ts"));
            }
        }

        return $status;
    }

    /**
     * Check if a network's broadcast needs to be restarted.
     * This is called by the worker loop to determine if we need to switch content.
     */
    public function needsRestart(Network $network): bool
    {
        // Not enabled - no restart needed
        if (! $network->broadcast_enabled) {
            return false;
        }

        // Process died - needs restart if there's content to play
        if (! $this->isProcessRunning($network) && $network->broadcast_pid !== null) {
            return $network->getCurrentProgramme() !== null;
        }

        // Process never started - needs start if there's content
        if ($network->broadcast_pid === null) {
            return $network->getCurrentProgramme() !== null;
        }

        return false;
    }

    /**
     * Restart the broadcast (stop if running, then start).
     * Used for content transitions.
     */
    public function restart(Network $network): bool
    {
        // Stop any existing process
        if ($network->broadcast_pid !== null) {
            $this->stop($network);
            $network->refresh();
        }

        // Start fresh
        return $this->start($network);
    }

    /**
     * Run a single tick of the broadcast worker for a network.
     * This should be called periodically by the worker command.
     *
     * @return array Status info about what happened
     */
    public function tick(Network $network): array
    {
        $result = [
            'network_id' => $network->id,
            'action' => 'none',
            'success' => true,
        ];

        // Refresh network state
        $network->refresh();

        // Not enabled - ensure stopped
        if (! $network->broadcast_enabled) {
            if ($network->broadcast_pid !== null) {
                $this->stop($network);
                $result['action'] = 'stopped';
            }

            return $result;
        }

        // Check if process is still running
        $isRunning = $this->isProcessRunning($network);

        // Process died but should be running
        if (! $isRunning && $network->broadcast_pid !== null) {
            Log::info('Broadcast process died, cleaning up', [
                'network_id' => $network->id,
                'old_pid' => $network->broadcast_pid,
            ]);

            $network->update([
                'broadcast_started_at' => null,
                'broadcast_pid' => null,
            ]);

            // Emit a metric/log entry for the crash
            Log::warning('HLS_METRIC: broadcast_crashed', [
                'network_id' => $network->id,
                'uuid' => $network->uuid,
                'old_pid' => $network->broadcast_pid,
            ]);
        }

        // Get current programme
        $programme = $network->getCurrentProgramme();

        // No current programme - stop if running
        if (! $programme) {
            if ($network->broadcast_pid !== null) {
                $this->stop($network);
                $result['action'] = 'stopped_no_content';
            }

            return $result;
        }

        // Should be running but isn't - start it
        if ($network->broadcast_pid === null) {
            $success = $this->start($network);
            $result['action'] = 'started';
            $result['success'] = $success;
            $result['programme'] = $programme->title;

            return $result;
        }

        // Running normally - check remaining time
        $remaining = $network->getCurrentRemainingDuration();
        $result['action'] = 'monitoring';
        $result['remaining_seconds'] = $remaining;

        return $result;
    }

    /**
     * Get all networks that should be broadcasting.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getBroadcastingNetworks()
    {
        return Network::where('broadcast_enabled', true)
            ->where('enabled', true)
            ->get();
    }
}
