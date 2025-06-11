<?php

namespace App\Services;

use Exception;
use App\Models\Channel;
use App\Models\Episode;
use App\Exceptions\SourceNotResponding;
use App\Traits\TracksActiveStreams;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use App\Jobs\MonitorStreamHealthJob;
use App\Services\ProxyService;
use Symfony\Component\Process\Process as SymfonyProcess;

class HlsStreamService
{
    use TracksActiveStreams;

    /**
     * Start an HLS stream with failover support for the given channel.
     * This method also tracks connections, performs pre-checks using ffprobe, and monitors for slow speed.
     *
     * @param string $type
     * @param Channel|Episode $model The Channel model instance
     * @param string $title The title of the channel
     */
    public function startStream(
        string $type,
        Channel|Episode $model, // This $model is the *original* requested channel/episode
        string $title           // This $title is the title of the *original* model
    ): ?object {
        $streamSettings = ProxyService::getStreamSettings();
        $ffprobeTimeout = $streamSettings['ffmpeg_ffprobe_timeout'] ?? 5;

        // --- Compile streamSourceIds ---
        $streamSourceIds = [$model->id]; // CORRECTED
        if ($type === 'channel' && $model instanceof Channel && !empty($model->failoverChannels) && $model->failoverChannels->count() > 0) { // CORRECTED: Using 'Channel' directly
            foreach ($model->failoverChannels as $failoverChannel) {
                $streamSourceIds[] = $failoverChannel->id;
            }
        }
        // TODO: Add similar logic for Episode failovers if applicable.

        // --- Initial check for any already running stream from the list ---
        $_tempStreamCollection = collect([$model]);
        if ($type === 'channel' && $model instanceof Channel && !empty($model->failoverChannels)) { // CORRECTED
            $_tempStreamCollection = $_tempStreamCollection->concat($model->failoverChannels);
        }
        foreach ($_tempStreamCollection as $streamToCheckIfExists) {
            if ($this->isRunning($type, $streamToCheckIfExists->id)) {
                $existingStreamTitle = ($type === 'channel')
                    ? ($streamToCheckIfExists->title_custom ?? $streamToCheckIfExists->title)
                    : $streamToCheckIfExists->title;
                $existingStreamTitle = strip_tags($existingStreamTitle);
                Log::channel('ffmpeg')->debug("[HLS Setup][OrigReq ID {$model->id}] Found existing running stream for {$type} ID {$streamToCheckIfExists->id} ('{$existingStreamTitle}') - reusing.");
                return $streamToCheckIfExists;
            }
        }


        Redis::set("hls:{$type}_last_seen:{$model->id}", now()->timestamp);
        Redis::sadd("hls:active_{$type}_ids", $model->id); // For the original requested model

        $currentSuccessfulStream = null;
        $currentIndexInSourceIds = -1;

        foreach ($streamSourceIds as $index => $streamIdToAttempt) {
            $streamModelBeingAttempted = null;
            if ($type === 'channel') {
                $streamModelBeingAttempted = Channel::with('playlist')->find($streamIdToAttempt); // CORRECTED (using Channel directly)
            } elseif ($type === 'episode') {
                $streamModelBeingAttempted = Episode::with('playlist')->find($streamIdToAttempt); // CORRECTED (using Episode directly)
            }

            if (!$streamModelBeingAttempted) {
                Log::channel('ffmpeg')->warning("[HLS Setup][OrigReq ID {$model->id}] Stream source {$type} ID {$streamIdToAttempt} not found in DB. Skipping.");
                continue;
            }

            $currentStreamTitleAttempt = 'Unknown Title';
            if ($type === 'channel' && $streamModelBeingAttempted instanceof Channel) { // CORRECTED
                $currentStreamTitleAttempt = ($streamModelBeingAttempted->title_custom ?? $streamModelBeingAttempted->title);
            } elseif ($type === 'episode' && $streamModelBeingAttempted instanceof Episode) { // CORRECTED
                $currentStreamTitleAttempt = $streamModelBeingAttempted->title;
            }
            $currentStreamTitleAttempt = strip_tags($currentStreamTitleAttempt);

            $playlist = $streamModelBeingAttempted->playlist;
            if (!$playlist) {
                Log::channel('ffmpeg')->warning("[HLS Setup][OrigReq ID {$model->id}] Playlist not found for {$type} ID {$streamModelBeingAttempted->id} ('{$currentStreamTitleAttempt}'). Skipping.");
                continue;
            }

            $activeStreams = $this->incrementActiveStreams($playlist->id);
            if ($this->wouldExceedStreamLimit($playlist->id, $playlist->available_streams ?? 1, $activeStreams)) {
                $this->decrementActiveStreams($playlist->id);
                Log::channel('ffmpeg')->debug("[HLS Setup][OrigReq ID {$model->id}] Max streams reached for playlist {$playlist->name} (ID {$playlist->id}). Skipping {$type} '{$currentStreamTitleAttempt}'.");
                continue;
            }

            $streamUrl = '';
            if ($type === 'channel' && $streamModelBeingAttempted instanceof Channel) { // CORRECTED
                $streamUrl = ($streamModelBeingAttempted->url_custom ?? $streamModelBeingAttempted->url);
            } elseif ($type === 'episode' && $streamModelBeingAttempted instanceof Episode) { // CORRECTED
                $streamUrl = $streamModelBeingAttempted->url;
            }
            $userAgent = $playlist->user_agent ?? null;

            try {
                $this->runPreCheck($type, $streamModelBeingAttempted->id, $streamUrl, $userAgent, $currentStreamTitleAttempt, $ffprobeTimeout);
                $this->startStreamWithSpeedCheck(
                    type: $type,
                    model: $streamModelBeingAttempted,
                    streamUrl: $streamUrl,
                    title: $currentStreamTitleAttempt,
                    playlistId: $playlist->id,
                    userAgent: $userAgent
                );

                Log::channel('ffmpeg')->debug(
                    "[HLS Setup][OrigReq ID {$model->id}] Successfully started HLS stream for {$type} {$currentStreamTitleAttempt} (ID: {$streamModelBeingAttempted->id}) on playlist {$playlist->id}."
                );

                $currentSuccessfulStream = $streamModelBeingAttempted;
                $currentIndexInSourceIds = $index;
                break;

            } catch (SourceNotResponding $e) {
                $this->decrementActiveStreams($playlist->id);
                Log::channel('ffmpeg')->error("[HLS Setup][OrigReq ID {$model->id}] Source not responding for {$type} '{$currentStreamTitleAttempt}' (ID {$streamModelBeingAttempted->id}): " . $e->getMessage());
            } catch (Exception $e) {
                $this->decrementActiveStreams($playlist->id);
                Log::channel('ffmpeg')->error("[HLS Setup][OrigReq ID {$model->id}] Error streaming {$type} '{$currentStreamTitleAttempt}' (ID {$streamModelBeingAttempted->id}): " . $e->getMessage());
            }
        }

        if ($currentSuccessfulStream) {
            if (!$currentSuccessfulStream->relationLoaded('playlist') || !$currentSuccessfulStream->playlist) {
                 // Eager loaded with 'with('playlist')' above, but double check or reload if necessary
            }
            if (!$currentSuccessfulStream->playlist) {
                 Log::channel('ffmpeg')->error("[HLS Setup][OrigReq ID {$model->id}] CRITICAL: Successful stream {$currentSuccessfulStream->id} has no playlist loaded for job dispatch. Monitor job may fail.");
                 return $currentSuccessfulStream;
            }


            $monitoringDisabledCacheKey = "hls:monitoring_disabled:{$type}:{$currentSuccessfulStream->id}";
            Cache::forget($monitoringDisabledCacheKey);

            MonitorStreamHealthJob::dispatch(
                $type,
                $currentSuccessfulStream->id,
                $model->id,
                $title,
                $currentSuccessfulStream->playlist->id,
                $streamSourceIds,
                $currentIndexInSourceIds
            )->delay(now()->addSeconds(config('streaming.monitor_job_interval_seconds', 10)));

            Log::channel('ffmpeg')->info(
                "[HLS Setup][OrigReq ID {$model->id}] Dispatched MonitorStreamHealthJob for active stream {$type} ID {$currentSuccessfulStream->id} (Index {$currentIndexInSourceIds}). Sources: [" . implode(',', $streamSourceIds) . "]"
            );

            return $currentSuccessfulStream;
        }

        Log::channel('ffmpeg')->error(
            "[HLS Setup][OrigReq ID {$model->id}] No available HLS streams for {$type} '{$title}' after trying all sources: [" . implode(', ', $streamSourceIds) . "]"
        );
        return null;
    }

    /**
     * Start a stream and monitor for slow speed.
     *
     * @param string $type
     * @param Channel|Episode $model
     * @param string $streamUrl
     * @param string $title
     * @param int $playlistId
     * @param string|null $userAgent
     * 
     * @return int The FFmpeg process ID
     * @throws Exception If the stream fails or speed drops below the threshold
     */
    private function startStreamWithSpeedCheck(
        string $type,
        Channel|Episode $model,
        string $streamUrl,
        string $title,
        int $playlistId,
        string|null $userAgent,
    ): int {
        // Setup the stream
        $cmd = $this->buildCmd($type, $model->id, $userAgent, $streamUrl);

        // Use proc_open approach similar to startStream
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        if ($type === 'episode') {
            $workingDir = Storage::disk('app')->path("hls/e/{$model->id}");
        } else {
            $workingDir = Storage::disk('app')->path("hls/{$model->id}");
        }
        $process = proc_open($cmd, $descriptors, $pipes, $workingDir);

        if (!is_resource($process)) {
            throw new Exception("Failed to launch FFmpeg for {$title}");
        }

        // Immediately close stdin/stdout
        fclose($pipes[0]);
        fclose($pipes[1]);

        // Make stderr non-blocking
        stream_set_blocking($pipes[2], false);

        // Spawn a little "reader" that pulls from stderr and logs
        $logger = Log::channel('ffmpeg');
        $stderr = $pipes[2];

        // Get the PID and cache it
        $cacheKey = "hls:pid:{$type}:{$model->id}";

        // Register shutdown function to ensure the pipe is drained
        register_shutdown_function(function () use (
            $stderr,
            $process,
            $logger
        ) {
            while (!feof($stderr)) {
                $line = fgets($stderr);
                if ($line !== false) {
                    $logger->error(trim($line));
                }
            }
            fclose($stderr);
            proc_close($process);
        });

        // Cache the actual FFmpeg PID
        $status = proc_get_status($process);
        $pid = $status['pid'];
        // $cacheKey is "hls:pid:{$type}:{$model->id}" which is correct for the PID
        Cache::forever($cacheKey, $pid);

        // Store the process start time
        $startTimeCacheKey = "hls:streaminfo:starttime:{$type}:{$model->id}";
        $currentTime = now()->timestamp;
        Redis::setex($startTimeCacheKey, 604800, $currentTime); // 7 days TTL
        Log::channel('ffmpeg')->debug("Stored ffmpeg process start time for {$type} ID {$model->id} at {$currentTime}");

        // Record timestamp in Redis (never expires until we prune)
        // This key represents when the startStream method was last invoked for this model,
        // which is different from the ffmpeg process actual start time. Keep for now.
        Redis::set("hls:{$type}_last_seen:{$model->id}", now()->timestamp);

        // Add to active IDs set
        Redis::sadd("hls:active_{$type}_ids", $model->id);

        Log::channel('ffmpeg')->debug("Streaming {$type} {$title} with command: {$cmd}");
        return $pid;
    }

    /**
     * Run a pre-check using ffprobe to validate the stream.
     *
     * @param string $modelType // 'channel' or 'episode'
     * @param int|string $modelId    // ID of the channel or episode
     * @param string $streamUrl
     * @param string|null $userAgent
     * @param string $title
     * @param int $ffprobeTimeout The timeout for the ffprobe process in seconds
     * 
     * @throws Exception If the pre-check fails
     */
    private function runPreCheck(string $modelType, $modelId, $streamUrl, $userAgent, $title, int $ffprobeTimeout)
    {
        $ffprobePath = config('proxy.ffprobe_path', 'ffprobe');
        
        // Updated command to include -show_format and remove -select_streams to get all streams for detailed info
        $cmd = "$ffprobePath -v quiet -print_format json -show_streams -show_format -user_agent " . escapeshellarg($userAgent) . " " . escapeshellarg($streamUrl);

        Log::channel('ffmpeg')->debug("[PRE-CHECK] Executing ffprobe command for [{$title}] with timeout {$ffprobeTimeout}s: {$cmd}");
        $precheckProcess = SymfonyProcess::fromShellCommandline($cmd);
        $precheckProcess->setTimeout($ffprobeTimeout);
        try {
            $precheckProcess->run();
            if (!$precheckProcess->isSuccessful()) {
                Log::channel('ffmpeg')->error("[PRE-CHECK] ffprobe failed for source [{$title}]. Exit Code: " . $precheckProcess->getExitCode() . ". Error Output: " . $precheckProcess->getErrorOutput());
                throw new SourceNotResponding("failed_ffprobe (Exit: " . $precheckProcess->getExitCode() . ")");
            }
            Log::channel('ffmpeg')->debug("[PRE-CHECK] ffprobe successful for source [{$title}].");

            // Check channel health
            $ffprobeJsonOutput = $precheckProcess->getOutput();
            $streamInfo = json_decode($ffprobeJsonOutput, true);
            $extractedDetails = [];

            if (json_last_error() === JSON_ERROR_NONE && !empty($streamInfo)) {
                // Format Section
                if (isset($streamInfo['format'])) {
                    $format = $streamInfo['format'];
                    $extractedDetails['format'] = [
                        'duration' => $format['duration'] ?? null,
                        'size' => $format['size'] ?? null,
                        'bit_rate' => $format['bit_rate'] ?? null,
                        'nb_streams' => $format['nb_streams'] ?? null,
                        'tags' => $format['tags'] ?? [],
                    ];
                }

                $videoStreamFound = false;
                $audioStreamFound = false;

                if (isset($streamInfo['streams']) && is_array($streamInfo['streams'])) {
                    foreach ($streamInfo['streams'] as $stream) {
                        if (!$videoStreamFound && isset($stream['codec_type']) && $stream['codec_type'] === 'video') {
                            $extractedDetails['video'] = [
                                'codec_long_name' => $stream['codec_long_name'] ?? null,
                                'width' => $stream['width'] ?? null,
                                'height' => $stream['height'] ?? null,
                                'color_range' => $stream['color_range'] ?? null,
                                'color_space' => $stream['color_space'] ?? null,
                                'color_transfer' => $stream['color_transfer'] ?? null,
                                'color_primaries' => $stream['color_primaries'] ?? null,
                                'tags' => $stream['tags'] ?? [],
                            ];
                            $logResolution = ($stream['width'] ?? 'N/A') . 'x' . ($stream['height'] ?? 'N/A');
                            Log::channel('ffmpeg')->debug(
                                "[PRE-CHECK] Source [{$title}] video stream: " .
                                "Codec: " . ($stream['codec_name'] ?? 'N/A') . ", " .
                                "Format: " . ($stream['pix_fmt'] ?? 'N/A') . ", " .
                                "Resolution: " . $logResolution . ", " .
                                "Profile: " . ($stream['profile'] ?? 'N/A') . ", " .
                                "Level: " . ($stream['level'] ?? 'N/A')
                            );
                            $videoStreamFound = true;
                        } elseif (!$audioStreamFound && isset($stream['codec_type']) && $stream['codec_type'] === 'audio') {
                            $extractedDetails['audio'] = [
                                'codec_name' => $stream['codec_name'] ?? null,
                                'profile' => $stream['profile'] ?? null,
                                'channels' => $stream['channels'] ?? null,
                                'channel_layout' => $stream['channel_layout'] ?? null,
                                'tags' => $stream['tags'] ?? [],
                            ];
                            $audioStreamFound = true;
                        }
                        if ($videoStreamFound && $audioStreamFound) {
                            break;
                        }
                    }
                }
                if (!empty($extractedDetails)) {
                    $detailsCacheKey = "hls:streaminfo:details:{$modelType}:{$modelId}";
                    Redis::setex($detailsCacheKey, 86400, json_encode($extractedDetails)); // Cache for 24 hours
                    Log::channel('ffmpeg')->debug("[PRE-CHECK] Cached detailed streaminfo for {$modelType} ID {$modelId}.");
                }
            } else {
                Log::channel('ffmpeg')->warning("[PRE-CHECK] Could not decode ffprobe JSON output for [{$title}]. Output: " . $ffprobeJsonOutput);
            }
        } catch (Exception $e) {
            throw new SourceNotResponding("failed_ffprobe_exception (" . $e->getMessage() . ")");
        }
    }

    /**
     * Stop FFmpeg for the given HLS stream channel (if currently running).
     *
     * @param string $type
     * @param string $id
     * @return bool
     */
    public function stopStream($type, $id): bool
    {
        $cacheKey = "hls:pid:{$type}:{$id}";
        $pid = Cache::get($cacheKey);
        $wasRunning = false;
        
        // Get the model to access playlist for stream count decrementing
        $model = null;
        if ($type === 'channel') {
            $model = Channel::find($id);
        } elseif ($type === 'episode') {
            $model = Episode::find($id);
        }
        
        if ($this->isRunning($type, $id)) {
            $wasRunning = true;

            // Give process time to cleanup gracefully
            posix_kill($pid, SIGTERM);
            $attempts = 0;
            while ($attempts < 30 && posix_kill($pid, 0)) {
                usleep(100000); // 100ms
                $attempts++;
            }

            // Force kill if still running
            if (posix_kill($pid, 0)) {
                posix_kill($pid, SIGKILL);
                Log::channel('ffmpeg')->warning("Force killed FFmpeg process {$pid} for {$type} {$id}");
            }
            Cache::forget($cacheKey);
        } else {
            Log::channel('ffmpeg')->warning("No running FFmpeg process for channel {$id} to stop.");
        }

        // Remove from active IDs set
        Redis::srem("hls:active_{$type}_ids", $id);
        Redis::del("hls:streaminfo:starttime:{$type}:{$id}");
        Redis::del("hls:streaminfo:details:{$type}:{$id}");

        // Cleanup on-disk HLS files
        if ($type === 'episode') {
            $storageDir = Storage::disk('app')->path("hls/e/{$id}");
        } else {
            $storageDir = Storage::disk('app')->path("hls/{$id}");
        }
        File::deleteDirectory($storageDir);

        // Decrement active streams count if we have the model and playlist
        if ($model && $model->playlist) {
            $this->decrementActiveStreams($model->playlist->id);
        }

        // Clean up any stream mappings that point to this stopped stream
        $mappingPattern = "hls:stream_mapping:{$type}:*";
        $mappingKeys = Redis::keys($mappingPattern);
        foreach ($mappingKeys as $key) {
            if (Cache::get($key) == $id) {
                Cache::forget($key);
                Log::channel('ffmpeg')->debug("Cleaned up stream mapping: {$key} -> {$id}");
            }
        }
        Log::channel('ffmpeg')->debug("Cleaned up stream resources for {$type} {$id}");

        return $wasRunning;
    }

    /**
     * Check if an HLS stream is currently running for the given channel ID.
     *
     * @param string $type
     * @param string $id
     * @return bool
     */
    public function isRunning($type, $id): bool
    {
        $cacheKey = "hls:pid:{$type}:{$id}";
        $pid = Cache::get($cacheKey);
        return $pid && posix_kill($pid, 0) && $this->isFfmpeg($pid);
    }

    /**
     * Get the PID of the currently running HLS stream for the given channel ID.
     *
     * @param string $type
     * @param string $id
     * @return bool
     */
    public function getPid($type, $id): ?int
    {
        $cacheKey = "hls:pid:{$type}:{$id}";
        return Cache::get($cacheKey);
    }

    /**
     * Return true if $pid is alive and matches an ffmpeg command.
     */
    protected function isFfmpeg(int $pid): bool
    {
        // On Linux systems
        if (PHP_OS_FAMILY === 'Linux' && file_exists("/proc/{$pid}/cmdline")) {
            $cmdline = file_get_contents("/proc/{$pid}/cmdline");
            return $cmdline && (strpos($cmdline, 'ffmpeg') !== false);
        }

        // On macOS/BSD systems
        if (PHP_OS_FAMILY === 'Darwin' || PHP_OS_FAMILY === 'BSD') {
            $output = [];
            exec("ps -p {$pid} -o command=", $output);
            return !empty($output) && strpos($output[0], 'ffmpeg') !== false;
        }

        // Default fallback (just check if process exists)
        return posix_kill($pid, 0);
    }

    /**
     * Build the FFmpeg command for HLS streaming.
     *
     * @param string $type
     * @param string $id
     * @param string|null $userAgent
     * @param string $streamUrl
     * 
     * @return string The complete FFmpeg command
     */
    private function buildCmd(
        $type,
        $id,
        $userAgent,
        $streamUrl
    ): string {
        // Get default stream settings
        $settings = ProxyService::getStreamSettings();
        $customCommandTemplate = $settings['ffmpeg_custom_command_template'] ?? null;

        // Setup the stream file paths
        if ($type === 'episode') {
            $storageDir = Storage::disk('app')->path("hls/e/{$id}");
        } else {
            $storageDir = Storage::disk('app')->path("hls/{$id}");
        }
        File::ensureDirectoryExists($storageDir, 0755);

        // Setup the stream URL
        $m3uPlaylist = "{$storageDir}/stream.m3u8";
        $segment = "{$storageDir}/segment_%03d.ts";

        // Construct segmentBaseUrl based on proxy_url_override
        $proxyOverrideUrl = config('proxy.url_override');
        if (!empty($proxyOverrideUrl)) {
            $parsedUrl = parse_url($proxyOverrideUrl);
            $scheme = $parsedUrl['scheme'] ?? 'http';
            $host = $parsedUrl['host'];
            $port = isset($parsedUrl['port']) ? ':' . $parsedUrl['port'] : '';
            $base = "{$scheme}://{$host}{$port}";
            $path = $type === 'channel' ? "/api/stream/{$id}/" : "/api/stream/e/{$id}/";
            $segmentBaseUrl = $base . $path;
        } else {
            $segmentBaseUrl = $type === 'channel' ? url("/api/stream/{$id}") . '/' : url("/api/stream/e/{$id}") . '/';
        }

        // Get ffmpeg path
        $ffmpegPath = config('proxy.ffmpeg_path') ?: $settings['ffmpeg_path'];
        if (empty($ffmpegPath)) {
            $ffmpegPath = 'jellyfin-ffmpeg';
        }

        // Determine the effective video codec based on config and settings
        $finalVideoCodec = ProxyService::determineVideoCodec(
            config('proxy.ffmpeg_codec_video', null),
            $settings['ffmpeg_codec_video'] ?? 'copy' // Default to 'copy' if not set
        );

        // Initialize Hardware Acceleration and Codec Specific Argument Variables
        $hwaccelInitArgs = '';    // For -init_hw_device
        $hwaccelInputArgs = '';   // For -hwaccel options before input (e.g., -hwaccel vaapi -hwaccel_output_format vaapi)
        $videoFilterArgs = '';    // For -vf
        $codecSpecificArgs = '';  // For encoder options like -profile:v, -preset, etc.
        $outputVideoCodec = $finalVideoCodec; // This might be overridden by hw accel logic

        // Get user defined options
        $userArgs = config('proxy.ffmpeg_additional_args', '');
        if (!empty($userArgs)) {
            $userArgs .= ' ';
        }

        // Command construction logic
        if (empty($customCommandTemplate)) {
            // VA-API Settings from GeneralSettings
            $vaapiEnabled = (($settings['hardware_acceleration_method'] ?? 'none') === 'vaapi');
            $vaapiDevice = escapeshellarg($settings['ffmpeg_vaapi_device'] ?? '/dev/dri/renderD128');
            $vaapiFilterFromSettings = $settings['ffmpeg_vaapi_video_filter'] ?? '';

            // QSV Settings from GeneralSettings
            $qsvEnabled = (($settings['hardware_acceleration_method'] ?? 'none') === 'qsv');
            $qsvDevice = escapeshellarg($settings['ffmpeg_qsv_device'] ?? '/dev/dri/renderD128');
            $qsvFilterFromSettings = $settings['ffmpeg_qsv_video_filter'] ?? '';
            $qsvEncoderOptions = $settings['ffmpeg_qsv_encoder_options'] ?? null;
            $qsvAdditionalArgs = $settings['ffmpeg_qsv_additional_args'] ?? null;

            $isVaapiCodec = str_contains($finalVideoCodec, '_vaapi');
            $isQsvCodec = str_contains($finalVideoCodec, '_qsv');

            if ($vaapiEnabled || $isVaapiCodec) {
                $outputVideoCodec = $isVaapiCodec ? $finalVideoCodec : 'h264_vaapi'; // Default to h264_vaapi if only toggle is on

                $hwaccelInitArgs = "-init_hw_device vaapi=va_device:{$vaapiDevice} -filter_hw_device va_device ";

                // These args are for full hardware acceleration (decode using VA-API)
                $hwaccelInputArgs = "-hwaccel vaapi -hwaccel_device va_device -hwaccel_output_format vaapi ";

                if (!empty($vaapiFilterFromSettings)) {
                    $videoFilterArgs = "-vf '" . trim($vaapiFilterFromSettings, "'") . "' ";
                } else {
                    $videoFilterArgs = ""; // No default -vf filter
                }
                // If $vaapiFilterFromSettings is empty, no -vf is added here for VA-API.
                // FFmpeg will handle conversions if possible, or fail if direct path isn't supported.

            } elseif ($qsvEnabled || $isQsvCodec) {
                // Only apply QSV if VA-API wasn't chosen/enabled
                $outputVideoCodec = $isQsvCodec ? $finalVideoCodec : 'h264_qsv'; // Default to h264_qsv
                $qsvDeviceName = 'qsv_hw'; // Internal FFmpeg label

                $hwaccelInitArgs = "-init_hw_device qsv={$qsvDeviceName}:{$qsvDevice} ";
                // These args are for full hardware acceleration (decode using QSV)
                $hwaccelInputArgs = "-hwaccel qsv -hwaccel_device {$qsvDeviceName} -hwaccel_output_format qsv ";

                if (!empty($qsvFilterFromSettings)) {
                    // This filter is applied to frames already in QSV format
                    $videoFilterArgs = "-vf '" . trim($qsvFilterFromSettings, "'") . "' ";
                } else {
                    // Add default QSV video filter for HLS if not set by user
                    $videoFilterArgs = "-vf 'hwupload=extra_hw_frames=64,scale_qsv=format=nv12' ";
                }
                if (!empty($qsvEncoderOptions)) { // $qsvEncoderOptions = $settings['ffmpeg_qsv_encoder_options']
                    $codecSpecificArgs = trim($qsvEncoderOptions) . " ";
                } else {
                    // Default QSV encoder options for HLS if not set by user
                    $codecSpecificArgs = "-preset medium -global_quality 23 "; // Ensure trailing space
                }
                if (!empty($qsvAdditionalArgs)) {
                    $userArgs = trim($qsvAdditionalArgs) . ($userArgs ? " " . $userArgs : "");
                }
            }
            // If neither VA-API nor QSV is applicable, $outputVideoCodec uses $finalVideoCodec (e.g. libx264 or copy)
            // and $hwaccelInitArgs, $hwaccelInputArgs, $videoFilterArgs remain empty from hw accel logic.

            // Get ffmpeg output codec formats
            $audioCodec = config('proxy.ffmpeg_codec_audio') ?: $settings['ffmpeg_codec_audio'];
            $subtitleCodec = config('proxy.ffmpeg_codec_subtitles') ?: $settings['ffmpeg_codec_subtitles'];

            // Start building ffmpeg output codec formats
            $outputFormat = "-c:v {$outputVideoCodec} " .
                ($codecSpecificArgs ? trim($codecSpecificArgs) . " " : "");

            // Conditionally add audio codec
            if (!empty($audioCodec)) {
                $outputFormat .= "-c:a {$audioCodec} ";
            }

            // Conditionally add subtitle codec
            if (!empty($subtitleCodec)) {
                $outputFormat .= "-c:s {$subtitleCodec} ";
            }
            $outputFormat = trim($outputFormat); // Trim trailing space

            // Reconstruct FFmpeg Command (ensure $ffmpegPath is escaped if it can contain spaces, though unlikely for a binary name)
            $cmd = escapeshellcmd($ffmpegPath) . ' ';
            $cmd .= $hwaccelInitArgs;  // e.g., -init_hw_device (goes before input options that use it, but after global options)
            $cmd .= $hwaccelInputArgs; // e.g., -hwaccel vaapi (these must go BEFORE the -i input)

            // Low-latency flags for better HLS performance
            $cmd .= '-fflags nobuffer+igndts -flags low_delay -avoid_negative_ts disabled ';

            // Input analysis optimization for faster stream start
            $cmd .= '-analyzeduration 1M -probesize 1M -max_delay 500000 -fpsprobesize 0 ';
            
            // Better error handling
            $cmd .= '-err_detect ignore_err -ignore_unknown ';

            // Use the user agent from settings, escape it. $userAgent parameter is ignored for now.
            $effectiveUserAgent = $userAgent ?: $settings['ffmpeg_user_agent'];
            $cmd .= "-user_agent " . escapeshellarg($effectiveUserAgent) . " -referer \"MyComputer\" " .
                '-multiple_requests 1 -reconnect_on_network_error 1 ' .
                '-reconnect_on_http_error 5xx,4xx,509 -reconnect_streamed 1 ' .
                '-reconnect_delay_max 2 -noautorotate ';

            $cmd .= $userArgs; // User-defined global args from config/proxy.php or QSV additional args
            $cmd .= '-i ' . escapeshellarg($streamUrl) . ' ';
            $cmd .= $videoFilterArgs; // e.g., -vf 'scale_vaapi=format=nv12' or -vf 'vpp_qsv=format=nv12'

            $cmd .= $outputFormat . ' ';
            $cmd .= '-vsync cfr '; // Add the vsync flag here
        } else {
            // Custom command template is provided
            $cmd = $customCommandTemplate;

            // Prepare placeholder values
            $hwaccelInitArgsValue = '';
            $hwaccelArgsValue = '';
            $videoFilterArgsValue = '';

            // QSV options
            $qsvEncoderOptionsValue = $settings['ffmpeg_qsv_encoder_options'] ? escapeshellarg($settings['ffmpeg_qsv_encoder_options']) : '';
            $qsvAdditionalArgsValue = $settings['ffmpeg_qsv_additional_args'] ? escapeshellarg($settings['ffmpeg_qsv_additional_args']) : '';

            // Determine codec type
            $isVaapiCodec = str_contains($finalVideoCodec, '_vaapi');
            $isQsvCodec = str_contains($finalVideoCodec, '_qsv');

            if ($settings['ffmpeg_vaapi_enabled'] ?? false) {
                $finalVideoCodec = $isVaapiCodec ? $finalVideoCodec : 'h264_vaapi'; // Default to h264_vaapi if not already set
                if (!empty($settings['ffmpeg_vaapi_device'])) {
                    $hwaccelInitArgsValue = "-init_hw_device vaapi=va_device:" . escapeshellarg($settings['ffmpeg_vaapi_device']) . " -filter_hw_device va_device ";
                    $hwaccelArgsValue = "-hwaccel vaapi -hwaccel_device va_device -hwaccel_output_format vaapi ";
                }
                if (!empty($settings['ffmpeg_vaapi_video_filter'])) {
                    $videoFilterArgsValue = "-vf " . escapeshellarg(trim($settings['ffmpeg_vaapi_video_filter'], "'\",")) . " ";
                }
            } else if ($settings['ffmpeg_qsv_enabled'] ?? false) {
                $finalVideoCodec = $isQsvCodec ? $finalVideoCodec : 'h264_qsv'; // Default to h264_qsv if not already set
                if (!empty($settings['ffmpeg_qsv_device'])) {
                    $hwaccelInitArgsValue = "-init_hw_device qsv=qsv_hw:" . escapeshellarg($settings['ffmpeg_qsv_device']) . " ";
                    $hwaccelArgsValue = '-hwaccel qsv -hwaccel_device qsv_hw -hwaccel_output_format qsv ';
                }
                if (!empty($settings['ffmpeg_qsv_video_filter'])) {
                    $videoFilterArgsValue = "-vf " . escapeshellarg(trim($settings['ffmpeg_qsv_video_filter'], "'\",")) . " ";
                }

                // Additional QSV specific options
                $codecSpecificArgs = $settings['ffmpeg_qsv_encoder_options'] ? escapeshellarg($settings['ffmpeg_qsv_encoder_options']) : '';
                if (!empty($settings['ffmpeg_qsv_additional_args'])) {
                    $userArgs = trim($settings['ffmpeg_qsv_additional_args']) . ($userArgs ? " " . $userArgs : "");
                }
            }

            $videoCodecForTemplate = $settings['ffmpeg_codec_video'] ?: 'copy';
            $audioCodecForTemplate = $settings['ffmpeg_codec_audio'] ?: 'copy';
            $subtitleCodecForTemplate = $settings['ffmpeg_codec_subtitles'] ?: 'copy';

            $outputCommandSegment = "-c:v {$outputVideoCodec} " .
                ($codecSpecificArgs ? trim($codecSpecificArgs) . " " : "") .
                "-c:a {$audioCodecForTemplate} -c:s {$subtitleCodecForTemplate}";

            $videoCodecArgs = "-c:v {$videoCodecForTemplate}" . ($codecSpecificArgs ? " " . trim($codecSpecificArgs) : "");
            $audioCodecArgs = "-c:a {$audioCodecForTemplate}";
            $subtitleCodecArgs = "-c:s {$subtitleCodecForTemplate}";

            // Perform replacements
            $cmd = str_replace('{FFMPEG_PATH}', escapeshellcmd($ffmpegPath), $cmd);
            $cmd = str_replace('{INPUT_URL}', escapeshellarg($streamUrl), $cmd);
            $cmd = str_replace('{OUTPUT_OPTIONS}', $outputCommandSegment, $cmd);
            $cmd = str_replace('{USER_AGENT}', $userAgent, $cmd); // $userAgent is already escaped
            $cmd = str_replace('{REFERER}', escapeshellarg("MyComputer"), $cmd);
            $cmd = str_replace('{HWACCEL_INIT_ARGS}', $hwaccelInitArgsValue, $cmd);
            $cmd = str_replace('{HWACCEL_ARGS}', $hwaccelArgsValue, $cmd);
            $cmd = str_replace('{VIDEO_FILTER_ARGS}', $videoFilterArgsValue, $cmd);
            $cmd = str_replace('{VIDEO_CODEC_ARGS}', $videoCodecArgs, $cmd);
            $cmd = str_replace('{AUDIO_CODEC_ARGS}', $audioCodecArgs, $cmd);
            $cmd = str_replace('{SUBTITLE_CODEC_ARGS}', $subtitleCodecArgs, $cmd);
            $cmd = str_replace('{QSV_ENCODER_OPTIONS}', $qsvEncoderOptionsValue, $cmd);
            $cmd = str_replace('{QSV_ADDITIONAL_ARGS}', $qsvAdditionalArgsValue, $cmd);
            $cmd = str_replace('{ADDITIONAL_ARGS}', $userArgs, $cmd); // If user wants to include general additional args
        }

        // Get HLS time from settings or use default
        $hlsTime = $settings['ffmpeg_hls_time'] ?? 4;
        $hlsListSize = 15; // Kept as a variable for future configurability

        // ... rest of the options and command suffix ...
        $cmd .= " -f hls -hls_time {$hlsTime} -hls_list_size {$hlsListSize} " .
            '-hls_flags delete_segments+append_list+split_by_time ' .
            '-use_wallclock_as_timestamps 1 -start_number 0 ' .
            '-hls_allow_cache 0 -hls_segment_type mpegts ' .
            '-hls_segment_filename ' . escapeshellarg($segment) . ' ' .
            '-hls_base_url ' . escapeshellarg($segmentBaseUrl) . ' ' .
            escapeshellarg($m3uPlaylist) . ' ';

        $cmd .= ($settings['ffmpeg_debug'] ? ' -loglevel verbose' : ' -hide_banner -nostats -loglevel error');

        return $cmd;
    }

    /**
     * Attempts to start a single, specific stream source.
     * If successful, it dispatches a MonitorStreamHealthJob for this new stream.
     * This method is primarily called by MonitorStreamHealthJob during sequential failover.
     *
     * @param string \$type ('channel' or 'episode')
     * @param \App\Models\Channel|\App\Models\Episode \$specificStreamModel The specific stream model instance to attempt.
     * @param string \$originalModelTitle Title of the original user-requested model (for logging).
     * @param array \$streamSourceIds The complete ordered list of source IDs for the original request.
     * @param int \$newCurrentIndexInSourceIds The index of \$specificStreamModel within \$streamSourceIds.
     * @param int \$originalModelId The ID of the original user-requested model.
     * @param int \$playlistIdOfSpecificStream The playlist ID of the \$specificStreamModel.
     * @return \App\Models\Channel|\App\Models\Episode|null The successfully started stream model, or null on failure.
     */
    private function attemptSpecificStreamSource(
        string $type,
        $specificStreamModel, // Actual type hint in file: \App\Models\Channel|\App\Models\Episode
        string $originalModelTitle,
        array $streamSourceIds,
        int $newCurrentIndexInSourceIds,
        int $originalModelId,
        int $playlistIdOfSpecificStream
    ): ?object {
        $streamIdToAttempt = $specificStreamModel->id;
        $currentStreamTitleAttempt = 'Unknown Title';
        if ($type === 'channel' && $specificStreamModel instanceof \App\Models\Channel) {
            $currentStreamTitleAttempt = ($specificStreamModel->title_custom ?? $specificStreamModel->title);
        } elseif ($type === 'episode' && $specificStreamModel instanceof \App\Models\Episode) {
            $currentStreamTitleAttempt = $specificStreamModel->title;
        }
        $currentStreamTitleAttempt = strip_tags($currentStreamTitleAttempt);

        // Simplified Log message using concatenation
        Log::channel('ffmpeg')->info("[SpecificAttempt] OrigReq ID " . $originalModelId . " - Attempting: " . $type . " ID " . $streamIdToAttempt . " ('" . $currentStreamTitleAttempt . "').");

        $playlist = $specificStreamModel->playlist;
        if (!$playlist || $playlist->id !== $playlistIdOfSpecificStream) {
            Log::channel('ffmpeg')->warning("[SpecificAttempt] OrigReq ID " . $originalModelId . " - Playlist ID mismatch or not found for " . $type . " ID " . $streamIdToAttempt . ". Using provided playlist ID " . $playlistIdOfSpecificStream . ". Model playlist: " . (isset($playlist->id) ? $playlist->id : 'N/A'));
        }

        $streamSettings = ProxyService::getStreamSettings();
        $ffprobeTimeout = $streamSettings['ffmpeg_ffprobe_timeout'] ?? 5;
        $availableStreamsCount = isset($playlist->available_streams) ? $playlist->available_streams : 1; // Safer access
        $activeStreams = $this->incrementActiveStreams($playlistIdOfSpecificStream);

        if ($this->wouldExceedStreamLimit($playlistIdOfSpecificStream, $availableStreamsCount, $activeStreams)) {
            $this->decrementActiveStreams($playlistIdOfSpecificStream);
            Log::channel('ffmpeg')->warning("[SpecificAttempt] OrigReq ID " . $originalModelId . " - Max streams for playlist ID " . $playlistIdOfSpecificStream . ". Cannot start " . $type . " ID " . $streamIdToAttempt . ".");
            return null;
        }

        $streamUrl = '';
        if ($type === 'channel' && $specificStreamModel instanceof \App\Models\Channel) {
            $streamUrl = ($specificStreamModel->url_custom ?? $specificStreamModel->url);
        } elseif ($type === 'episode' && $specificStreamModel instanceof \App\Models\Episode) {
            $streamUrl = $specificStreamModel->url;
        }
        $userAgent = isset($playlist->user_agent) ? $playlist->user_agent : null; // Safer access

        try {
            $this->runPreCheck($type, $streamIdToAttempt, $streamUrl, $userAgent, $currentStreamTitleAttempt, $ffprobeTimeout);
            $this->startStreamWithSpeedCheck(
                type: $type,
                model: $specificStreamModel,
                streamUrl: $streamUrl,
                title: $currentStreamTitleAttempt,
                playlistId: $playlistIdOfSpecificStream,
                userAgent: $userAgent
            );

            Log::channel('ffmpeg')->debug("[SpecificAttempt] OrigReq ID " . $originalModelId . " - Successfully started: " . $type . " ID " . $streamIdToAttempt . " ('" . $currentStreamTitleAttempt . "').");

            $monitoringDisabledCacheKey = "hls:monitoring_disabled:{$type}:{$streamIdToAttempt}";
            Cache::forget($monitoringDisabledCacheKey);

            MonitorStreamHealthJob::dispatch(
                $type,
                $streamIdToAttempt,
                $originalModelId,
                $originalModelTitle,
                $playlistIdOfSpecificStream,
                $streamSourceIds,
                $newCurrentIndexInSourceIds
            )->delay(now()->addSeconds(config('streaming.monitor_job_interval_seconds', 10)));

            Log::channel('ffmpeg')->info("[SpecificAttempt] OrigReq ID " . $originalModelId . " - Dispatched MonitorStreamHealthJob for " . $type . " ID " . $streamIdToAttempt . " (Index " . $newCurrentIndexInSourceIds . ").");

            return $specificStreamModel;

        } catch (SourceNotResponding $e) {
            $this->decrementActiveStreams($playlistIdOfSpecificStream);
            Log::channel('ffmpeg')->error("[SpecificAttempt] OrigReq ID " . $originalModelId . " - SourceNotResponding for " . $type . " ID " . $streamIdToAttempt . " ('" . $currentStreamTitleAttempt . "'): " . $e->getMessage());
            return null;
        } catch (Exception $e) {
            $this->decrementActiveStreams($playlistIdOfSpecificStream);
            Log::channel('ffmpeg')->error("[SpecificAttempt] OrigReq ID " . $originalModelId . " - Exception for " . $type . " ID " . $streamIdToAttempt . " ('" . $currentStreamTitleAttempt . "'): " . $e->getMessage());
            return null;
        }
    }
}
