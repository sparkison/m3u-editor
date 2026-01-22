<?php

namespace App\Services;

use App\Models\MediaServerIntegration;
use App\Models\Network;
use App\Models\NetworkProgramme;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process as SymfonyProcess;

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
        if (! $network->enabled) {
            Log::warning('Cannot start broadcast: network not enabled', [
                'network_id' => $network->id,
                'network_name' => $network->name,
            ]);

            return false;
        }

        if (! $network->broadcast_enabled) {
            Log::warning('Cannot start broadcast: broadcast not enabled', [
                'network_id' => $network->id,
                'network_name' => $network->name,
            ]);

            return false;
        }

        // Check if scheduled start is enabled and we haven't reached the time yet
        if ($network->broadcast_schedule_enabled && $network->broadcast_scheduled_start) {
            if (now()->lt($network->broadcast_scheduled_start)) {
                Log::info('Cannot start broadcast - waiting for scheduled start time', [
                    'network_id' => $network->id,
                    'scheduled_start' => $network->broadcast_scheduled_start->toIso8601String(),
                    'seconds_remaining' => now()->diffInSeconds($network->broadcast_scheduled_start, false),
                ]);

                return false;
            }
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

        // Determine programme to broadcast.
        // Priority: current programme > next programme > persisted (only if still valid)
        $programme = $network->getCurrentProgramme();

        if (! $programme) {
            // No current programme - try to get the next upcoming one
            $programme = $network->getNextProgramme();

            if ($programme) {
                Log::info('No current programme, using next upcoming programme', [
                    'network_id' => $network->id,
                    'next_programme_id' => $programme->id,
                    'next_programme_title' => $programme->title,
                    'next_programme_start' => $programme->start_time->toIso8601String(),
                ]);
            }
        }

        // Only fall back to persisted programme if it's STILL AIRING (not ended)
        if (! $programme && $network->broadcast_programme_id) {
            $persistedProgramme = NetworkProgramme::find($network->broadcast_programme_id);
            if ($persistedProgramme && $persistedProgramme->end_time->gt(now())) {
                // Persisted programme is still valid (hasn't ended yet)
                $programme = $persistedProgramme;
                Log::info('Using persisted programme that is still airing', [
                    'network_id' => $network->id,
                    'programme_id' => $programme->id,
                    'programme_title' => $programme->title,
                ]);
            } else {
                // Persisted programme has ended - clear the stale reference
                Log::info('Clearing stale persisted programme reference (programme has ended)', [
                    'network_id' => $network->id,
                    'old_programme_id' => $network->broadcast_programme_id,
                ]);
                $network->update([
                    'broadcast_programme_id' => null,
                    'broadcast_initial_offset_seconds' => null,
                ]);
            }
        }

        if (! $programme) {
            Log::warning('No current programme to broadcast', [
                'network_id' => $network->id,
            ]);

            $network->update([
                'broadcast_requested' => false,
                'broadcast_error' => 'No programme scheduled to broadcast.',
            ]);

            return false;
        }

        // Compute seek position: if there is a persisted broadcast reference use it, otherwise use current programme seek
        $seekPosition = $network->getPersistedBroadcastSeekForNow() ?? $network->getCurrentSeekPosition();

        // Build and start FFmpeg process
        $command = $this->buildFfmpegCommand($network, $programme, $seekPosition);
        if (! $command) {
            Log::error('Failed to build FFmpeg command', [
                'network_id' => $network->id,
            ]);

            $network->update([
                'broadcast_requested' => false,
                'broadcast_error' => 'Failed to build FFmpeg command.',
            ]);

            return false;
        }

        $result = $this->executeCommand($network, $command, $programme, $seekPosition);

        // Clear error on successful start
        if ($result) {
            $network->update(['broadcast_error' => null]);
        } else {
            // Clear broadcast_requested on failure so it doesn't stay stuck on "Starting"
            $network->update(['broadcast_requested' => false]);
        }

        return $result;
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

            // Clear any persisted broadcast reference and remove HLS files if they exist.
            // When explicitly stopped (not a programme transition), reset sequences to start fresh
            $network->update([
                'broadcast_started_at' => null,
                'broadcast_pid' => null,
                'broadcast_programme_id' => null,
                'broadcast_initial_offset_seconds' => null,
                'broadcast_requested' => false,
                // Reset sequences on explicit stop - next start will be a fresh broadcast
                'broadcast_segment_sequence' => 0,
                'broadcast_discontinuity_sequence' => 0,
            ]);

            // Remove lingering playlist and segment files to prevent stale content from being served
            try {
                $hlsPath = $network->getHlsStoragePath();
                if (File::isDirectory($hlsPath)) {
                    foreach (File::glob("{$hlsPath}/*.m3u8") as $file) {
                        File::delete($file);
                    }
                    foreach (File::glob("{$hlsPath}/*.m3u8.tmp") as $file) {
                        File::delete($file);
                    }
                    foreach (File::glob("{$hlsPath}/*.ts") as $file) {
                        File::delete($file);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to remove HLS files while stopping broadcast', [
                    'network_id' => $network->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return true;
        }

        // Try graceful termination first (SIGTERM)
        if ($this->signalProcess($pid, SIGTERM)) {
            // Wait a moment for graceful shutdown
            usleep(500000); // 500ms

            // Check if process is still running
            if (! $this->isProcessRunning($network)) {
                Log::info("🔴 BROADCAST STOPPED: {$network->name} (Network ID: {$network->id}, PID: {$pid})");

                // Explicit stop - reset sequences for fresh start next time
                $network->update([
                    'broadcast_started_at' => null,
                    'broadcast_pid' => null,
                    'broadcast_programme_id' => null,
                    'broadcast_initial_offset_seconds' => null,
                    'broadcast_requested' => false,
                    'broadcast_segment_sequence' => 0,
                    'broadcast_discontinuity_sequence' => 0,
                ]);

                // Remove any HLS files
                $this->cleanupHlsForNetwork($network);

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

        Log::info("🔴 BROADCAST FORCE-STOPPED: {$network->name} (Network ID: {$network->id}, PID: {$pid})");

        // Explicit force-stop - reset sequences for fresh start next time
        $network->update([
            'broadcast_started_at' => null,
            'broadcast_pid' => null,
            'broadcast_requested' => false,
            'broadcast_segment_sequence' => 0,
            'broadcast_discontinuity_sequence' => 0,
        ]);

        // Ensure HLS files are removed on force-stop as well
        $this->cleanupHlsForNetwork($network);

        return true;
    }

    /**
     * Check if a network's broadcast process is running.
     * Uses posix_kill with signal 0 for cross-platform compatibility (macOS/Linux).
     * Also checks for zombie processes which appear as "running" to posix_kill but are dead.
     */
    public function isProcessRunning(Network $network): bool
    {
        $pid = $network->broadcast_pid;

        if (! $pid) {
            return false;
        }

        // Use posix_kill with signal 0 to check if process exists (cross-platform)
        // Returns true if process exists, false if it doesn't
        // Signal 0 doesn't actually send a signal, just checks existence
        if (! @posix_kill($pid, 0)) {
            return false;
        }

        // Check if process is a zombie (dead but not reaped)
        // Zombies still exist in process table so posix_kill returns true,
        // but they are effectively dead and won't process any data
        $statusFile = "/proc/{$pid}/status";
        if (file_exists($statusFile)) {
            $status = @file_get_contents($statusFile);
            if ($status !== false && preg_match('/^State:\s+Z/m', $status)) {
                // Zombie process - try to reap it
                @pcntl_waitpid($pid, $status, WNOHANG);

                return false;
            }
        }

        return true;
    }

    /**
     * Build the FFmpeg command for a programme.
     *
     * @return array|null Command array or null on failure
     */
    public function buildFfmpegCommand(Network $network, NetworkProgramme $programme, int $seekPosition = 0): ?array
    {
        $hlsPath = $network->getHlsStoragePath();
        // Use provided seek position (may come from persisted reference) or fall back to current seek
        $remainingDuration = $network->getCurrentRemainingDuration();

        Log::info("📍 BROADCAST SEEK CALCULATION: {$network->name}", [
            'network_id' => $network->id,
            'programme_id' => $programme->id,
            'programme_title' => $programme->title,
            'programme_start' => $programme->start_time->toIso8601String(),
            'programme_end' => $programme->end_time->toIso8601String(),
            'now' => now()->toIso8601String(),
            'seek_position_seconds' => $seekPosition,
            'seek_position_formatted' => gmdate('H:i:s', $seekPosition),
            'remaining_duration_seconds' => $remainingDuration,
            'remaining_duration_formatted' => gmdate('H:i:s', $remainingDuration),
        ]);

        // Get stream URL with seek position built-in (media server handles seeking)
        $streamUrl = $this->getStreamUrl($network, $programme, $seekPosition);
        if (! $streamUrl) {
            return null;
        }

        // Base FFmpeg command
        $command = ['ffmpeg', '-y'];

        // SEEK FIRST: Use FFmpeg's -ss for input-level seeking BEFORE -i
        // This is the most reliable way to start at the correct position.
        // Placing -ss before -i makes FFmpeg seek at the demuxer level (fast, accurate for most formats)
        if ($seekPosition > 0) {
            $command[] = '-ss';
            $command[] = (string) $seekPosition;

            Log::info("📍 FFmpeg INPUT SEEK: Starting at {$seekPosition} seconds", [
                'network_id' => $network->id,
                'seek_seconds' => $seekPosition,
                'seek_formatted' => gmdate('H:i:s', $seekPosition),
            ]);
        }

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

        // HLS output options for continuous streaming across programme transitions
        // Critical for seamless playback without loops or stalls:
        //
        // start_number: Continue segment numbering from where we left off
        //   - Prevents players from seeing duplicate sequence numbers
        //   - Essential for append_list to work correctly across restarts
        //
        // hls_list_size: Number of segments to keep in playlist
        //   - 0 = keep all (not recommended for live)
        //   - 20 = ~2 min buffer at 6s segments (good default)
        //
        // HLS Flags explained:
        //   - delete_segments: Remove old .ts files beyond hls_list_size (saves disk)
        //   - append_list: Append to existing playlist instead of overwriting
        //   - program_date_time: Add EXT-X-PROGRAM-DATE-TIME for DVR/seeking
        //   - omit_endlist: Never write EXT-X-ENDLIST (live stream, not VOD)
        //   - discont_start: Add EXT-X-DISCONTINUITY at start (signals format change)
        //   - independent_segments: Each segment can be decoded independently
        //
        // The combination of start_number + discont_start ensures:
        // 1. Playlist continuity across programme transitions
        // 2. Proper discontinuity signaling when format/timing changes
        // 3. Players don't loop back to old content
        //
        // NOTE: We do NOT use append_list because FFmpeg's implementation doesn't
        // truly maintain continuity across process restarts. Instead, we use
        // start_number to continue segment numbering and let FFmpeg write a fresh
        // playlist. The insertDiscontinuityMarker() method handles discontinuity
        // insertion when transitioning between programmes.
        $startNumber = max(0, $network->broadcast_segment_sequence ?? 0);

        // HLS flags:
        // - delete_segments: Remove old .ts files beyond hls_list_size (saves disk)
        // - program_date_time: Add EXT-X-PROGRAM-DATE-TIME for DVR/seeking
        // - omit_endlist: Never write EXT-X-ENDLIST (live stream, not VOD)
        // - discont_start: Add EXT-X-DISCONTINUITY at start when resuming
        // - independent_segments: Each segment can be decoded independently
        $hlsFlags = 'delete_segments+program_date_time+omit_endlist';

        // Add discont_start flag if this is a content transition (sequence > 0 means we've had content before)
        if ($startNumber > 0) {
            $hlsFlags .= '+discont_start';
        }

        // Add independent_segments for better player compatibility
        $hlsFlags .= '+independent_segments';

        $command = array_merge($command, [
            '-f', 'hls',
            '-hls_time', (string) ($network->segment_duration ?? 6),
            '-hls_list_size', (string) ($network->hls_list_size ?? 20),
            '-start_number', (string) $startNumber,
            '-hls_flags', $hlsFlags,
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

        $service = MediaServerService::make($integration);
        $request = request();
        $request->merge(['static' => 'true']); // static stream for HLS

        // Use media server's native seeking if we need to seek
        if ($seekSeconds > 0) {
            // Jellyfin/Emby use ticks (100-nanosecond intervals)
            $startTimeTicks = $seekSeconds * 10_000_000;
            $request->merge(['StartTimeTicks' => $startTimeTicks]);

            Log::debug('📍 Media server seek applied', [
                'network_id' => $network->id,
                'item_id' => $itemId,
                'seek_seconds' => $seekSeconds,
                'seek_ticks' => $startTimeTicks,
            ]);
        }

        $streamUrl = $service->getDirectStreamUrl($request, $itemId, 'ts');

        return $streamUrl;
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
    protected function executeCommand(Network $network, array $command, ?NetworkProgramme $programme = null, int $initialOffsetSeconds = 0): bool
    {
        $commandString = implode(' ', array_map('escapeshellarg', $command));

        Log::info('Starting broadcast FFmpeg process', [
            'network_id' => $network->id,
            'command' => $commandString,
        ]);

        // Create output log file
        $logFile = $network->getHlsStoragePath().'/ffmpeg.log';

        // Set PATH explicitly to include common FFmpeg installation locations
        // This ensures ffmpeg is found in both local (Homebrew) and Docker environments
        $pathPrefix = 'PATH=/usr/local/bin:/usr/bin:/bin:/opt/homebrew/bin:/opt/homebrew/sbin:$PATH';
        $fullCommand = "{$pathPrefix} {$commandString} >> ".escapeshellarg($logFile).' 2>&1';

        // Use SymfonyProcess to start FFmpeg in background (like Channel model uses for ffprobe)
        $process = SymfonyProcess::fromShellCommandline($fullCommand);
        $process->setTimeout(null); // No timeout for long-running broadcast
        $process->start();

        // Get the PID
        $pid = $process->getPid();

        if (! $pid) {
            Log::error('Failed to start FFmpeg process - no PID', [
                'network_id' => $network->id,
            ]);

            return false;
        }

        // Wait a moment and verify process is still running
        usleep(500000); // 500ms

        if (! $process->isRunning()) {
            $logContent = @file_get_contents($logFile);

            Log::error('FFmpeg process exited immediately', [
                'network_id' => $network->id,
                'pid' => $pid,
                'log' => $logContent,
            ]);

            // Store error message for user feedback
            $network->update([
                'broadcast_error' => $this->parseFfmpegError($logContent),
            ]);

            return false;
        }

        // Update network with broadcast info and persist broadcast reference
        $update = [
            'broadcast_started_at' => Carbon::now(),
            'broadcast_pid' => $pid,
            'broadcast_programme_id' => $programme?->id,
            'broadcast_initial_offset_seconds' => $initialOffsetSeconds,
            'broadcast_requested' => true,
        ];

        $network->update($update);

        Log::info("🟢 BROADCAST STARTED: {$network->name} (Network ID: {$network->id}, PID: {$pid})", $update);

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
     * Cross-platform compatible (macOS/Linux).
     */
    protected function signalProcess(int $pid, int $signal): bool
    {
        // posix_kill returns false if process doesn't exist, no need for separate check
        return @posix_kill($pid, $signal);
    }

    /**
     * Insert a discontinuity marker into the HLS playlist.
     *
     * This is called when transitioning between programmes to signal to HLS players
     * that there's a format/timing change. The discontinuity marker tells players
     * to reset their decoders and not assume continuity with previous segments.
     *
     * @return bool True if discontinuity was inserted successfully
     */
    protected function insertDiscontinuityMarker(Network $network): bool
    {
        $hlsPath = $network->getHlsStoragePath();
        $playlistPath = "{$hlsPath}/live.m3u8";

        if (! File::exists($playlistPath)) {
            Log::debug('No playlist to insert discontinuity into', ['network_id' => $network->id]);

            return false;
        }

        try {
            $content = File::get($playlistPath);
            $lines = explode("\n", $content);

            // Find where to insert discontinuity - before the last segment or at the end
            // We want it BEFORE the EXT-X-ENDLIST if present, or at the end otherwise
            $insertIndex = count($lines);

            for ($i = count($lines) - 1; $i >= 0; $i--) {
                $line = trim($lines[$i]);
                if ($line === '#EXT-X-ENDLIST') {
                    $insertIndex = $i;
                    break;
                }
                // Stop searching once we hit actual content
                if (str_starts_with($line, '#EXTINF:') || str_ends_with($line, '.ts')) {
                    $insertIndex = $i + 1;
                    break;
                }
            }

            // Insert discontinuity marker
            array_splice($lines, $insertIndex, 0, ['#EXT-X-DISCONTINUITY']);

            // Write back
            $newContent = implode("\n", $lines);
            File::put($playlistPath, $newContent);

            // Increment discontinuity sequence for next FFmpeg process
            $network->increment('broadcast_discontinuity_sequence');

            Log::info('Inserted discontinuity marker into playlist', [
                'network_id' => $network->id,
                'discontinuity_sequence' => $network->broadcast_discontinuity_sequence,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('Failed to insert discontinuity marker', [
                'network_id' => $network->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Update the segment sequence counter based on existing segments.
     *
     * Scans the HLS directory for existing segments and sets the sequence
     * counter to continue from where we left off.
     */
    protected function updateSegmentSequence(Network $network): void
    {
        $hlsPath = $network->getHlsStoragePath();
        $segments = File::glob("{$hlsPath}/live*.ts");

        if (empty($segments)) {
            return;
        }

        $maxSequence = 0;
        foreach ($segments as $segment) {
            // Extract sequence number from filename: live000123.ts -> 123
            if (preg_match('/live(\d+)\.ts$/', basename($segment), $matches)) {
                $seq = (int) $matches[1];
                if ($seq > $maxSequence) {
                    $maxSequence = $seq;
                }
            }
        }

        // Set sequence to next number
        $nextSequence = $maxSequence + 1;
        if ($nextSequence > ($network->broadcast_segment_sequence ?? 0)) {
            $network->update(['broadcast_segment_sequence' => $nextSequence]);

            Log::debug('Updated segment sequence', [
                'network_id' => $network->id,
                'next_sequence' => $nextSequence,
            ]);
        }
    }

    /**
     * Clean up only old segments, preserving the playlist for continuity.
     *
     * This is used during programme transitions where we want to keep the
     * playlist intact but remove segments that are no longer needed.
     *
     * @param  int  $keepCount  Number of recent segments to keep (0 = delete all)
     */
    protected function cleanupOldSegments(Network $network, int $keepCount = 0): void
    {
        $hlsPath = $network->getHlsStoragePath();

        if (! File::isDirectory($hlsPath)) {
            return;
        }

        $segments = File::glob("{$hlsPath}/live*.ts");
        if (empty($segments)) {
            return;
        }

        // Sort by modification time (oldest first)
        usort($segments, function ($a, $b) {
            return File::lastModified($a) - File::lastModified($b);
        });

        // Keep the most recent segments
        $toDelete = $keepCount > 0 ? array_slice($segments, 0, -$keepCount) : $segments;

        $deleted = 0;
        foreach ($toDelete as $segment) {
            try {
                File::delete($segment);
                $deleted++;
            } catch (\Throwable $e) {
                Log::warning('Failed to delete old segment', [
                    'file' => $segment,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($deleted > 0) {
            Log::debug('Cleaned up old segments', [
                'network_id' => $network->id,
                'deleted' => $deleted,
                'kept' => count($segments) - $deleted,
            ]);
        }
    }

    /**
     * Remove HLS files for a network.
     * Called when stopping broadcast or cleaning up after crashes.
     */
    protected function cleanupHlsForNetwork(Network $network): void
    {
        $hlsPath = $network->getHlsStoragePath();

        if (! File::isDirectory($hlsPath)) {
            return;
        }

        $deletedPlaylists = 0;
        $deletedSegments = 0;

        // Clean up playlists
        foreach (File::glob("{$hlsPath}/*.m3u8") as $file) {
            try {
                File::delete($file);
                $deletedPlaylists++;
            } catch (\Throwable $e) {
                Log::warning('Failed to delete m3u8', ['file' => $file, 'error' => $e->getMessage()]);
            }
        }

        foreach (File::glob("{$hlsPath}/*.m3u8.tmp") as $file) {
            try {
                File::delete($file);
                $deletedPlaylists++;
            } catch (\Throwable $e) {
                Log::warning('Failed to delete tmp m3u8', ['file' => $file, 'error' => $e->getMessage()]);
            }
        }

        // Clean up orphaned segments (from crashes where FFmpeg couldn't delete them)
        foreach (File::glob("{$hlsPath}/*.ts") as $file) {
            try {
                File::delete($file);
                $deletedSegments++;
            } catch (\Throwable $e) {
                Log::warning('Failed to delete segment', ['file' => $file, 'error' => $e->getMessage()]);
            }
        }

        if ($deletedPlaylists > 0 || $deletedSegments > 0) {
            Log::info('Cleaned up HLS files', [
                'network_id' => $network->id,
                'playlists' => $deletedPlaylists,
                'segments' => $deletedSegments,
            ]);
        }
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

        // Check if scheduled start time is enabled and we haven't reached it yet
        if ($network->broadcast_schedule_enabled && $network->broadcast_scheduled_start) {
            if (now()->lt($network->broadcast_scheduled_start)) {
                // Not time yet - ensure not running
                if ($network->broadcast_pid !== null) {
                    $this->stop($network);
                    $result['action'] = 'stopped_waiting_for_schedule';
                } else {
                    $result['action'] = 'waiting_for_scheduled_start';
                    $result['scheduled_start'] = $network->broadcast_scheduled_start->toIso8601String();
                    $result['seconds_until_start'] = now()->diffInSeconds($network->broadcast_scheduled_start, false);
                }

                return $result;
            }
        }

        // Check if process is still running
        $isRunning = $this->isProcessRunning($network);

        // Process died but should be running
        if (! $isRunning && $network->broadcast_pid !== null) {
            $oldProgramme = $network->broadcast_programme_id
                ? NetworkProgramme::find($network->broadcast_programme_id)
                : null;

            // Determine if this was a normal completion (programme ended) or an unexpected crash
            $programmeEnded = $oldProgramme && $oldProgramme->end_time->lte(now());

            if ($programmeEnded) {
                // Normal completion - programme finished, FFmpeg exited as expected
                Log::info('🔄 BROADCAST PROGRAMME COMPLETED: Moving to next programme', [
                    'network_id' => $network->id,
                    'network_name' => $network->name,
                    'old_pid' => $network->broadcast_pid,
                    'completed_programme_id' => $oldProgramme->id,
                    'completed_programme_title' => $oldProgramme->title,
                    'segment_sequence' => $network->broadcast_segment_sequence,
                    'discontinuity_sequence' => $network->broadcast_discontinuity_sequence,
                ]);

                // Update segment sequence counter based on existing segments
                // This ensures the next FFmpeg process continues numbering correctly
                $this->updateSegmentSequence($network);

                // Insert discontinuity marker into existing playlist
                // This signals to players that the content format/timing is changing
                $this->insertDiscontinuityMarker($network);

                // Clear the persisted reference so we get the next programme on restart
                // BUT keep the segment and discontinuity sequences for continuity!
                $network->update([
                    'broadcast_started_at' => null,
                    'broadcast_pid' => null,
                    'broadcast_programme_id' => null,
                    'broadcast_initial_offset_seconds' => null,
                    'broadcast_error' => null,
                    // Note: broadcast_segment_sequence and broadcast_discontinuity_sequence
                    // are preserved to maintain playlist continuity
                ]);

                // DO NOT wipe the playlist! We want continuity.
                // Only clean up OLD segments that are no longer referenced in the playlist.
                // FFmpeg's delete_segments flag handles this, but we clean orphans just in case.
                try {
                    // Keep at least hls_list_size segments for buffering
                    $keepCount = $network->hls_list_size ?? 20;
                    $this->cleanupOldSegments($network, $keepCount);

                    Log::info('Cleaned up old segments after programme completion (playlist preserved)', [
                        'network_id' => $network->id,
                        'kept_segments' => $keepCount,
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('Failed to cleanup old segments after programme completion', [
                        'network_id' => $network->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            } else {
                // Unexpected crash - programme was still supposed to be airing
                Log::warning('🔴 BROADCAST CRASHED: Process died unexpectedly', [
                    'network_id' => $network->id,
                    'network_name' => $network->name,
                    'old_pid' => $network->broadcast_pid,
                    'crashed_programme_id' => $oldProgramme?->id,
                    'crashed_programme_title' => $oldProgramme?->title,
                    'uptime_seconds' => $network->broadcast_started_at
                        ? now()->diffInSeconds($network->broadcast_started_at)
                        : null,
                ]);

                // Update segment sequence for recovery
                $this->updateSegmentSequence($network);

                // Keep persisted reference for crash recovery (resume from where we left off)
                $network->update([
                    'broadcast_started_at' => null,
                    'broadcast_pid' => null,
                    'broadcast_error' => 'Broadcast crashed unexpectedly. Will auto-restart if programme is still active.',
                ]);

                // For crashes, we also preserve the playlist for seamless recovery
                // Only clean up orphaned segments (those not in the playlist)
                try {
                    $keepCount = $network->hls_list_size ?? 20;
                    $this->cleanupOldSegments($network, $keepCount);
                } catch (\Throwable $e) {
                    Log::warning('Failed to cleanup orphaned HLS segments after crash', [
                        'network_id' => $network->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                // Emit a metric/log entry for the crash
                Log::warning('HLS_METRIC: broadcast_crashed', [
                    'network_id' => $network->id,
                    'uuid' => $network->uuid,
                    'old_pid' => $network->broadcast_pid,
                    'uptime_seconds' => $network->broadcast_started_at
                        ? now()->diffInSeconds($network->broadcast_started_at)
                        : null,
                ]);
            }
        }

        // Get current or next programme
        $programme = $network->getCurrentProgramme();

        // If no current programme, try to get the next one (for seamless transitions)
        if (! $programme) {
            $programme = $network->getNextProgramme();
        }

        // No current or next programme - stop if running
        if (! $programme) {
            if ($network->broadcast_pid !== null) {
                $this->stop($network);
                $result['action'] = 'stopped_no_content';
            } else {
                $result['action'] = 'no_content';
            }

            return $result;
        }

        // Should be running but isn't - start it (only if user requested it)
        if ($network->broadcast_pid === null && $network->broadcast_requested) {
            // Calculate where we should resume from
            $seekPosition = $network->getPersistedBroadcastSeekForNow() ?? $network->getCurrentSeekPosition();

            Log::info('🔄 BROADCAST RECOVERY: Restarting broadcast', [
                'network_id' => $network->id,
                'network_name' => $network->name,
                'programme_id' => $programme->id,
                'programme_title' => $programme->title,
                'resume_position_seconds' => $seekPosition,
                'resume_position_formatted' => gmdate('H:i:s', $seekPosition),
                'is_crash_recovery' => isset($result['action']) && $result['action'] !== 'none',
            ]);

            $success = $this->start($network);
            $result['action'] = 'started';
            $result['success'] = $success;
            $result['programme'] = $programme->title;
            $result['resume_position_seconds'] = $seekPosition;

            return $result;
        }

        // broadcast_requested is false and not running - just report idle
        if ($network->broadcast_pid === null) {
            $result['action'] = 'idle';

            return $result;
        }

        // Running normally - check remaining time
        $remaining = $network->getCurrentRemainingDuration();
        $result['action'] = 'monitoring';
        $result['remaining_seconds'] = $remaining;

        // Note: Segment cleanup is handled automatically by FFmpeg's delete_segments flag
        // No manual cleanup needed during normal operation

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

    /**
     * Parse FFmpeg error log to extract user-friendly error message.
     */
    protected function parseFfmpegError(?string $logContent): string
    {
        if (empty($logContent)) {
            return 'FFmpeg process failed to start. Check server logs for details.';
        }

        // Check for common errors
        if (str_contains($logContent, 'No such file or directory')) {
            if (str_contains($logContent, 'ffmpeg')) {
                return 'FFmpeg is not installed or not in PATH. Please install FFmpeg on the server.';
            }

            return 'File not found: '.$logContent;
        }

        if (str_contains($logContent, 'Permission denied')) {
            return 'Permission denied. Check file/directory permissions.';
        }

        if (str_contains($logContent, 'Connection refused') || str_contains($logContent, 'Unable to open')) {
            return 'Cannot connect to media server. Check media server URL and API key.';
        }

        if (str_contains($logContent, 'Invalid data found')) {
            return 'Invalid stream format. Media server may not be transcoding correctly.';
        }

        if (str_contains($logContent, 'HTTP error')) {
            return 'HTTP error accessing media server stream.';
        }

        // Return first line of error if nothing specific matched
        $lines = explode("\n", trim($logContent));

        return $lines[0] ?? 'Unknown error occurred while starting broadcast.';
    }
}
