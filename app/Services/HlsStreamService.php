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
    protected function startStreamWithSpeedCheck(
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
    protected function runPreCheck(string $modelType, $modelId, $streamUrl, $userAgent, $title, int $ffprobeTimeout)
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
    public function isFfmpeg(int $pid): bool
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
        string $type,
        string $id,
        ?string $passedUserAgent, // Can be null
        string $streamUrl
    ): string {
        $settings = ProxyService::getStreamSettings();
        $customCommandTemplate = $settings['ffmpeg_custom_command_template'] ?? null;

        $storageDir = Storage::disk('app')->path($type === 'episode' ? "hls/e/{$id}" : "hls/{$id}");
        File::ensureDirectoryExists($storageDir, 0o755); // Octal permission

        $m3uPlaylistPath = "{$storageDir}/stream.m3u8";
        $segmentPathTemplate = "{$storageDir}/segment_%03d.ts";
        $segmentListEntryPrefixValue = ($type === 'channel' ? "hls/{$id}/" : "hls/e/{$id}/");
        $graphFilePath = "{$storageDir}/ffmpeg-graph-{$id}.txt";

        $ffmpegPath = config('proxy.ffmpeg_path') ?: ($settings['ffmpeg_path'] ?? 'jellyfin-ffmpeg');
        $effectiveUserAgent = $passedUserAgent ?: ($settings['ffmpeg_user_agent'] ?? 'LibVLC/3.0.20');

        $cmd = '';
        $usingCustomTemplate = !empty($customCommandTemplate);

        if ($usingCustomTemplate) {
            $cmd = $customCommandTemplate;

            $finalVideoCodec = ProxyService::determineVideoCodec(
                config('proxy.ffmpeg_codec_video', null),
                $settings['ffmpeg_codec_video'] ?? 'copy'
            );

            $hwaccelInitArgsValue = '';
            $hwaccelArgsValue = '';
            $videoFilterArgsValue = '';
            $qsvEncoderOptionsSetting = $settings['ffmpeg_qsv_encoder_options'] ?? '';
            $qsvAdditionalArgsSetting = $settings['ffmpeg_qsv_additional_args'] ?? '';

            $codecSpecificOutputArgs = '';
            $outputVideoCodecForTemplate = $finalVideoCodec;

            $vaapiEnabled = (($settings['hardware_acceleration_method'] ?? 'none') === 'vaapi');
            $qsvEnabled = (($settings['hardware_acceleration_method'] ?? 'none') === 'qsv');
            $isVaapiCodec = str_contains($finalVideoCodec, '_vaapi');
            $isQsvCodec = str_contains($finalVideoCodec, '_qsv');

            if ($vaapiEnabled || $isVaapiCodec) {
                $outputVideoCodecForTemplate = $isVaapiCodec ? $finalVideoCodec : 'h264_vaapi';
                $vaapiDevice = escapeshellarg($settings['ffmpeg_vaapi_device'] ?? '/dev/dri/renderD128');
                $hwaccelInitArgsValue = "-init_hw_device vaapi=va_device:{$vaapiDevice} -filter_hw_device va_device";
                $hwaccelArgsValue = "-hwaccel vaapi -hwaccel_device va_device -hwaccel_output_format vaapi";
                if (!empty($settings['ffmpeg_vaapi_video_filter'])) {
                    $videoFilterArgsValue = "-vf " . escapeshellarg(trim($settings['ffmpeg_vaapi_video_filter'], "'","));
                }
            } elseif ($qsvEnabled || $isQsvCodec) {
                $outputVideoCodecForTemplate = $isQsvCodec ? $finalVideoCodec : 'h264_qsv';
                $qsvDevice = escapeshellarg($settings['ffmpeg_qsv_device'] ?? '/dev/dri/renderD128');
                $qsvDeviceName = 'qsv_hw';
                $hwaccelInitArgsValue = "-init_hw_device qsv={$qsvDeviceName}:{$qsvDevice}";
                $hwaccelArgsValue = "-hwaccel qsv -hwaccel_device {$qsvDeviceName} -hwaccel_output_format qsv";
                if (!empty($settings['ffmpeg_qsv_video_filter'])) {
                    $videoFilterArgsValue = "-vf " . escapeshellarg(trim($settings['ffmpeg_qsv_video_filter'], "'","));
                }
                if (!empty($qsvEncoderOptionsSetting)) {
                    $codecSpecificOutputArgs = $qsvEncoderOptionsSetting;
                }
            }

            $audioCodecForTemplate = $settings['ffmpeg_codec_audio'] ?: (config('proxy.ffmpeg_codec_audio') ?: 'copy');
            $subtitleCodecForTemplate = $settings['ffmpeg_codec_subtitles'] ?: (config('proxy.ffmpeg_codec_subtitles') ?: 'copy');

            $outputCommandSegmentForTemplate = "-c:v {$outputVideoCodecForTemplate}" . ($codecSpecificOutputArgs ? " {$codecSpecificOutputArgs}" : "") .
                                             " -c:a {$audioCodecForTemplate}" .
                                             " -c:s {$subtitleCodecForTemplate}";

            $videoCodecArgsForTemplate = "-c:v {$outputVideoCodecForTemplate}" . ($codecSpecificOutputArgs ? " {$codecSpecificOutputArgs}" : "");
            $audioCodecArgsForTemplate = "-c:a {$audioCodecForTemplate}";
            $subtitleCodecArgsForTemplate = "-c:s {$subtitleCodecForTemplate}";

            $baseAdditionalArgs = config('proxy.ffmpeg_additional_args', '');
            $combinedAdditionalArgs = $qsvAdditionalArgsSetting;
            if (!empty($baseAdditionalArgs)) {
                if (!empty($combinedAdditionalArgs)) {
                    $combinedAdditionalArgs .= ' ';
                }
                $combinedAdditionalArgs .= $baseAdditionalArgs;
            }

            $cmd = str_replace('{FFMPEG_PATH}', escapeshellcmd($ffmpegPath), $cmd);
            $cmd = str_replace('{INPUT_URL}', escapeshellarg($streamUrl), $cmd);
            $cmd = str_replace('{USER_AGENT}', escapeshellarg($effectiveUserAgent), $cmd);
            $cmd = str_replace('{REFERER}', escapeshellarg("MyComputer"), $cmd);
            $cmd = str_replace('{HWACCEL_INIT_ARGS}', trim($hwaccelInitArgsValue), $cmd);
            $cmd = str_replace('{HWACCEL_ARGS}', trim($hwaccelArgsValue), $cmd);
            $cmd = str_replace('{VIDEO_FILTER_ARGS}', trim($videoFilterArgsValue), $cmd);
            $cmd = str_replace('{OUTPUT_OPTIONS}', trim($outputCommandSegmentForTemplate), $cmd);
            $cmd = str_replace('{VIDEO_CODEC_ARGS}', trim($videoCodecArgsForTemplate), $cmd);
            $cmd = str_replace('{AUDIO_CODEC_ARGS}', trim($audioCodecArgsForTemplate), $cmd);
            $cmd = str_replace('{SUBTITLE_CODEC_ARGS}', trim($subtitleCodecArgsForTemplate), $cmd);
            $cmd = str_replace('{QSV_ENCODER_OPTIONS}', $qsvEncoderOptionsSetting, $cmd);
            $cmd = str_replace('{QSV_ADDITIONAL_ARGS}', $qsvAdditionalArgsSetting, $cmd);
            $cmd = str_replace('{ADDITIONAL_ARGS}', $combinedAdditionalArgs, $cmd);
            $cmd = str_replace('{M3U_PLAYLIST_PATH}', escapeshellarg($m3uPlaylistPath), $cmd);
            $cmd = str_replace('{SEGMENT_PATH_TEMPLATE}', escapeshellarg($segmentPathTemplate), $cmd);
            $cmd = str_replace('{SEGMENT_LIST_ENTRY_PREFIX}', escapeshellarg($segmentListEntryPrefixValue), $cmd);
            $cmd = str_replace('{GRAPH_FILE_PATH}', escapeshellarg($graphFilePath), $cmd);

        } else {
            // Default command building logic
            $finalVideoCodec = ProxyService::determineVideoCodec(
                config('proxy.ffmpeg_codec_video', null),
                $settings['ffmpeg_codec_video'] ?? 'copy'
            );

            $hwaccelInitArgs = '';
            $hwaccelInputArgs = '';
            $videoFilterArgs = '';
            $codecSpecificArgs = '';
            $outputVideoCodec = $finalVideoCodec;
            $userArgs = config('proxy.ffmpeg_additional_args', '');

            $vaapiEnabled = (($settings['hardware_acceleration_method'] ?? 'none') === 'vaapi');
            if ($vaapiEnabled || str_contains($finalVideoCodec, '_vaapi')) {
                $outputVideoCodec = str_contains($finalVideoCodec, '_vaapi') ? $finalVideoCodec : 'h264_vaapi';
                $vaapiDevice = escapeshellarg($settings['ffmpeg_vaapi_device'] ?? '/dev/dri/renderD128');
                $hwaccelInitArgs = "-init_hw_device vaapi=va_device:{$vaapiDevice} -filter_hw_device va_device ";
                $hwaccelInputArgs = "-hwaccel vaapi -hwaccel_device va_device -hwaccel_output_format vaapi ";
                if (!empty($settings['ffmpeg_vaapi_video_filter'])) {
                    $videoFilterArgs = "-vf '" . trim($settings['ffmpeg_vaapi_video_filter'], "'") . "' ";
                }
            } elseif ((($settings['hardware_acceleration_method'] ?? 'none') === 'qsv') || str_contains($finalVideoCodec, '_qsv')) {
                $outputVideoCodec = str_contains($finalVideoCodec, '_qsv') ? $finalVideoCodec : 'h264_qsv';
                $qsvDevice = escapeshellarg($settings['ffmpeg_qsv_device'] ?? '/dev/dri/renderD128');
                $qsvDeviceName = 'qsv_hw';
                $hwaccelInitArgs = "-init_hw_device qsv={$qsvDeviceName}:{$qsvDevice} ";
                $hwaccelInputArgs = "-hwaccel qsv -hwaccel_device {$qsvDeviceName} -hwaccel_output_format qsv ";
                if (!empty($settings['ffmpeg_qsv_video_filter'])) {
                    $videoFilterArgs = "-vf '" . trim($settings['ffmpeg_qsv_video_filter'], "'") . "' ";
                } else {
                    $videoFilterArgs = "-vf 'hwupload=extra_hw_frames=64,scale_qsv=format=nv12' ";
                }

                $qsvEncoderOptions = $settings['ffmpeg_qsv_encoder_options'] ?? '';
                if (!empty($qsvEncoderOptions)) {
                    $codecSpecificArgs = trim($qsvEncoderOptions) . " ";
                } else {
                    $codecSpecificArgs = "-preset medium -global_quality 23 ";
                }

                $qsvAdditionalArgs = $settings['ffmpeg_qsv_additional_args'] ?? '';
                if (!empty($qsvAdditionalArgs)) {
                    $userArgs = trim($qsvAdditionalArgs) . ($userArgs ? " " . $userArgs : "");
                }
            }

            if (!empty($userArgs) && substr($userArgs, -1) !== ' ') {
                $userArgs .= ' ';
            }

            $audioCodecSetting = $settings['ffmpeg_codec_audio'] ?: (config('proxy.ffmpeg_codec_audio') ?: '');
            $subtitleCodecSetting = $settings['ffmpeg_codec_subtitles'] ?: (config('proxy.ffmpeg_codec_subtitles') ?: '');

            $outputFormat = "-c:v {$outputVideoCodec} ";
            $outputFormat .= $codecSpecificArgs;

            if (!empty($audioCodecSetting)) {
                $outputFormat .= "-c:a {$audioCodecSetting} ";
            } else {
                $outputFormat .= "-c:a copy ";
            }
            if (!empty($subtitleCodecSetting)) {
                $outputFormat .= "-c:s {$subtitleCodecSetting} ";
            } else {
                $outputFormat .= "-c:s copy ";
            }

            $cmd = escapeshellcmd($ffmpegPath) . ' ';
            $cmd .= $hwaccelInitArgs;
            $cmd .= $hwaccelInputArgs;
            $cmd .= '-fflags nobuffer+igndts -flags low_delay -avoid_negative_ts disabled ';
            $cmd .= '-analyzeduration 1M -probesize 1M -max_delay 500000 -fpsprobesize 0 ';
            $cmd .= '-err_detect ignore_err -ignore_unknown ';
            $cmd .= "-user_agent " . escapeshellarg($effectiveUserAgent) . " -referer "MyComputer" " .
                '-multiple_requests 1 -reconnect_on_network_error 1 ' .
                '-reconnect_on_http_error 5xx,4xx,509 -reconnect_streamed 1 ' .
                '-reconnect_delay_max 2 -noautorotate ';
            $cmd .= $userArgs;
            $cmd .= '-i ' . escapeshellarg($streamUrl) . ' ';
            $cmd .= $videoFilterArgs;
            $cmd .= trim($outputFormat) . ' ';
            $cmd .= '-vsync cfr';
        }

        if (!$usingCustomTemplate) {
            $proxyOverrideUrl = config('proxy.url_override');
            $segmentBaseUrlForDefaultHls = '';
            if (!empty($proxyOverrideUrl)) {
                $parsedUrl = parse_url($proxyOverrideUrl);
                $scheme = $parsedUrl['scheme'] ?? 'http';
                $host = $parsedUrl['host'] ?? '';
                $port = isset($parsedUrl['port']) ? ':' . $parsedUrl['port'] : '';
                $base = "{$scheme}://{$host}{$port}";
                $pathSegment = $type === 'channel' ? "/api/stream/{$id}/" : "/api/stream/e/{$id}/";
                $segmentBaseUrlForDefaultHls = $base . $pathSegment;
            } else {
                $segmentBaseUrlForDefaultHls = ($type === 'channel' ? url("/api/stream/{$id}") . '/' : url("/api/stream/e/{$id}") . '/');
            }

            $hlsTime = $settings['ffmpeg_hls_time'] ?? 4;
            $hlsListSize = 15;

            $currentCmd = trim($cmd);

            $hlsCmdPart = "-f hls -hls_time {$hlsTime} -hls_list_size {$hlsListSize} " .
                '-hls_flags delete_segments+append_list+split_by_time ' .
                '-use_wallclock_as_timestamps 1 -start_number 0 ' .
                '-hls_allow_cache 0 -hls_segment_type mpegts ' .
                '-hls_segment_filename ' . escapeshellarg($segmentPathTemplate) . ' ' .
                '-hls_base_url ' . escapeshellarg($segmentBaseUrlForDefaultHls) . ' ' .
                escapeshellarg($m3uPlaylistPath);

            $logCmdPart = ($settings['ffmpeg_debug'] ? '-loglevel verbose' : '-hide_banner -nostats -loglevel error');

            if (!empty($currentCmd)) {
                $cmd = $currentCmd . ' ' . $hlsCmdPart . ' ' . $logCmdPart;
            } else {
                // This case should ideally not be hit if ffmpegPath is always correctly set for the default command.
                // However, to be safe, prepend ffmpegPath if cmd is empty.
                $cmd = escapeshellcmd($ffmpegPath) . ' ' . $hlsCmdPart . ' ' . $logCmdPart;
            }
        }

        return trim($cmd);
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
    public function attemptSpecificStreamSource(
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
