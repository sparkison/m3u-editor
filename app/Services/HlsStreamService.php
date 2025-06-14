<?php

namespace App\Services;

use App\Exceptions\SourceNotResponding;
use App\Jobs\MonitorStreamHealthJob;
use App\Models\Channel;
use App\Models\Episode;
use App\Services\ProxyService;
use App\Traits\TracksActiveStreams;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process as SymfonyProcess;

class HlsStreamService
{
    use TracksActiveStreams;

    /**
     * Start an HLS stream with failover support for the given channel.
     *
     * @param  string  $type
     * @param  Channel|Episode  $model  The Channel or Episode model instance
     * @param  string  $title  The title of the channel or episode
     */
    public function startStream(
        string $type,
        Channel|Episode $model, // This $model is the *original* requested channel/episode
        string $title          // This $title is the title of the *original* model
    ): ?object {
        $streamSettings = ProxyService::getStreamSettings();
        $ffprobeTimeout = $streamSettings['ffmpeg_ffprobe_timeout'] ?? 5;

        // --- Compile streamSourceIds ---
        $streamSourceIds = [$model->id];
        if ($type === 'channel' && $model instanceof Channel && ! empty($model->failoverChannels) && $model->failoverChannels->count() > 0) {
            foreach ($model->failoverChannels as $failoverChannel) {
                $streamSourceIds[] = $failoverChannel->id;
            }
        }
        // TODO: Add similar logic for Episode failovers if applicable.

        // --- Initial check for any already running stream from the list ---
        $_tempStreamCollection = collect([$model]);
        if ($type === 'channel' && $model instanceof Channel && ! empty($model->failoverChannels)) {
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
                $streamModelBeingAttempted = Channel::with('playlist')->find($streamIdToAttempt);
            } elseif ($type === 'episode') {
                $streamModelBeingAttempted = Episode::with('playlist')->find($streamIdToAttempt);
            }

            if (! $streamModelBeingAttempted) {
                Log::channel('ffmpeg')->warning("[HLS Setup][OrigReq ID {$model->id}] Stream source {$type} ID {$streamIdToAttempt} not found in DB. Skipping.");
                continue;
            }

            $currentStreamTitleAttempt = 'Unknown Title';
            if ($type === 'channel' && $streamModelBeingAttempted instanceof Channel) {
                $currentStreamTitleAttempt = ($streamModelBeingAttempted->title_custom ?? $streamModelBeingAttempted->title);
            } elseif ($type === 'episode' && $streamModelBeingAttempted instanceof Episode) {
                $currentStreamTitleAttempt = $streamModelBeingAttempted->title;
            }
            $currentStreamTitleAttempt = strip_tags($currentStreamTitleAttempt);

            $playlist = $streamModelBeingAttempted->playlist;
            if (! $playlist) {
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
            if ($type === 'channel' && $streamModelBeingAttempted instanceof Channel) {
                $streamUrl = ($streamModelBeingAttempted->url_custom ?? $streamModelBeingAttempted->url);
            } elseif ($type === 'episode' && $streamModelBeingAttempted instanceof Episode) {
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
                Log::channel('ffmpeg')->error("[HLS Setup][OrigReq ID {$model->id}] Source not responding for {$type} '{$currentStreamTitleAttempt}' (ID {$streamModelBeingAttempted->id}): ".$e->getMessage());
            } catch (Exception $e) {
                $this->decrementActiveStreams($playlist->id);
                Log::channel('ffmpeg')->error("[HLS Setup][OrigReq ID {$model->id}] Error streaming {$type} '{$currentStreamTitleAttempt}' (ID {$streamModelBeingAttempted->id}): ".$e->getMessage());
            }
        }

        if ($currentSuccessfulStream) {
            if (! $currentSuccessfulStream->playlist) {
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
                "[HLS Setup][OrigReq ID {$model->id}] Dispatched MonitorStreamHealthJob for active stream {$type} ID {$currentSuccessfulStream->id} (Index {$currentIndexInSourceIds}). Sources: [".implode(',', $streamSourceIds).']'
            );

            return $currentSuccessfulStream;
        }

        Log::channel('ffmpeg')->error(
            "[HLS Setup][OrigReq ID {$model->id}] No available HLS streams for {$type} '{$title}' after trying all sources: [".implode(', ', $streamSourceIds).']'
        );

        return null;
    }

    /**
     * Start a stream and perform necessary setup.
     *
     * @param  string  $type
     * @param  Channel|Episode  $model
     * @param  string  $streamUrl
     * @param  string  $title
     * @param  int  $playlistId
     * @param  string|null  $userAgent
     * @return int The FFmpeg process ID
     *
     * @throws Exception If the stream fails to launch
     */
    protected function startStreamWithSpeedCheck(
        string $type,
        Channel|Episode $model,
        string $streamUrl,
        string $title,
        int $playlistId,
        ?string $userAgent
    ): int {
        $cmd = $this->buildCmd($type, $model->id, $userAgent, $streamUrl);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $workingDir = Storage::disk('app')->path($type === 'episode' ? "hls/e/{$model->id}" : "hls/{$model->id}");

        $process = proc_open($cmd, $descriptors, $pipes, $workingDir);

        if (! is_resource($process)) {
            throw new Exception("Failed to launch FFmpeg for {$title}");
        }

        fclose($pipes[0]);
        fclose($pipes[1]);
        stream_set_blocking($pipes[2], false);

        $logger = Log::channel('ffmpeg');
        $stderr = $pipes[2];

        register_shutdown_function(function () use (
            $stderr,
            $process,
            $logger
        ) {
            while (! feof($stderr)) {
                $line = fgets($stderr);
                if ($line !== false) {
                    $logger->error(trim($line));
                }
            }
            fclose($stderr);
            proc_close($process);
        });

        $status = proc_get_status($process);
        $pid = $status['pid'];

        $cacheKey = "hls:pid:{$type}:{$model->id}";
        Cache::forever($cacheKey, $pid);

        $startTimeCacheKey = "hls:streaminfo:starttime:{$type}:{$model->id}";
        $currentTime = now()->timestamp;
        Redis::setex($startTimeCacheKey, 604800, $currentTime); // 7 days TTL
        Log::channel('ffmpeg')->debug("Stored ffmpeg process start time for {$type} ID {$model->id} at {$currentTime}");

        Redis::set("hls:{$type}_last_seen:{$model->id}", now()->timestamp);
        Redis::sadd("hls:active_{$type}_ids", $model->id);

        Log::channel('ffmpeg')->debug("Streaming {$type} {$title} with command: {$cmd}");

        return $pid;
    }

    /**
     * Run a pre-check using ffprobe to validate the stream.
     *
     * @param  string  $modelType  // 'channel' or 'episode'
     * @param  int|string  $modelId  // ID of the channel or episode
     * @param  string  $streamUrl
     * @param  string|null  $userAgent
     * @param  string  $title
     * @param  int  $ffprobeTimeout  The timeout for the ffprobe process in seconds
     * @throws Exception If the pre-check fails
     */
    protected function runPreCheck(string $modelType, $modelId, $streamUrl, $userAgent, $title, int $ffprobeTimeout)
    {
        $ffprobePath = config('proxy.ffprobe_path', 'ffprobe');
        $cmd = "$ffprobePath -v quiet -print_format json -show_streams -show_format -user_agent ".escapeshellarg($userAgent)." ".escapeshellarg($streamUrl);

        Log::channel('ffmpeg')->debug("[PRE-CHECK] Executing ffprobe command for [{$title}] with timeout {$ffprobeTimeout}s: {$cmd}");
        $precheckProcess = SymfonyProcess::fromShellCommandline($cmd);
        $precheckProcess->setTimeout($ffprobeTimeout);
        try {
            $precheckProcess->run();
            if (! $precheckProcess->isSuccessful()) {
                Log::channel('ffmpeg')->error("[PRE-CHECK] ffprobe failed for source [{$title}]. Exit Code: ".$precheckProcess->getExitCode().'. Error Output: '.$precheckProcess->getErrorOutput());
                throw new SourceNotResponding('failed_ffprobe (Exit: '.$precheckProcess->getExitCode().')');
            }
            Log::channel('ffmpeg')->debug("[PRE-CHECK] ffprobe successful for source [{$title}].");

            $ffprobeJsonOutput = $precheckProcess->getOutput();
            $streamInfo = json_decode($ffprobeJsonOutput, true);
            $extractedDetails = [];

            if (json_last_error() === JSON_ERROR_NONE && ! empty($streamInfo)) {
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
                        if (! $videoStreamFound && isset($stream['codec_type']) && $stream['codec_type'] === 'video') {
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
                            $logResolution = ($stream['width'] ?? 'N/A').'x'.($stream['height'] ?? 'N/A');
                            Log::channel('ffmpeg')->debug(
                                "[PRE-CHECK] Source [{$title}] video stream: ".
                                'Codec: '.($stream['codec_name'] ?? 'N/A').', '.
                                'Format: '.($stream['pix_fmt'] ?? 'N/A').', '.
                                'Resolution: '.$logResolution.', '.
                                'Profile: '.($stream['profile'] ?? 'N/A').', '.
                                'Level: '.($stream['level'] ?? 'N/A')
                            );
                            $videoStreamFound = true;
                        } elseif (! $audioStreamFound && isset($stream['codec_type']) && $stream['codec_type'] === 'audio') {
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
                if (! empty($extractedDetails)) {
                    $detailsCacheKey = "hls:streaminfo:details:{$modelType}:{$modelId}";
                    Redis::setex($detailsCacheKey, 86400, json_encode($extractedDetails)); // Cache for 24 hours
                    Log::channel('ffmpeg')->debug("[PRE-CHECK] Cached detailed streaminfo for {$modelType} ID {$modelId}.");
                }
            } else {
                Log::channel('ffmpeg')->warning("[PRE-CHECK] Could not decode ffprobe JSON output for [{$title}]. Output: ".$ffprobeJsonOutput);
            }
        } catch (Exception $e) {
            throw new SourceNotResponding('failed_ffprobe_exception ('.$e->getMessage().')');
        }
    }

    /**
     * Stop FFmpeg for the given HLS stream.
     *
     * @param  string  $type
     * @param  string  $id
     * @return bool
     */
    public function stopStream($type, $id): bool
    {
        $cacheKey = "hls:pid:{$type}:{$id}";
        $pid = Cache::get($cacheKey);
        $wasRunning = false;

        $model = null;
        if ($type === 'channel') {
            $model = Channel::find($id);
        } elseif ($type === 'episode') {
            $model = Episode::find($id);
        }

        if ($this->isRunning($type, $id)) {
            $wasRunning = true;

            posix_kill($pid, SIGTERM);
            $attempts = 0;
            while ($attempts < 30 && posix_kill($pid, 0)) {
                usleep(100000); // 100ms
                $attempts++;
            }

            if (posix_kill($pid, 0)) {
                posix_kill($pid, SIGKILL);
                Log::channel('ffmpeg')->warning("Force killed FFmpeg process {$pid} for {$type} {$id}");
            }
            Cache::forget($cacheKey);
        } else {
            Log::channel('ffmpeg')->warning("No running FFmpeg process for {$type} ID {$id} to stop.");
        }

        Redis::srem("hls:active_{$type}_ids", $id);
        Redis::del("hls:streaminfo:starttime:{$type}:{$id}");
        Redis::del("hls:streaminfo:details:{$type}:{$id}");

        $storageDir = Storage::disk('app')->path($type === 'episode' ? "hls/e/{$id}" : "hls/{$id}");
        File::deleteDirectory($storageDir);

        if ($model && $model->playlist) {
            $this->decrementActiveStreams($model->playlist->id);
        }

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
     * Check if an HLS stream is currently running for the given ID.
     *
     * @param  string  $type
     * @param  string  $id
     * @return bool
     */
    public function isRunning($type, $id): bool
    {
        $cacheKey = "hls:pid:{$type}:{$id}";
        $pid = Cache::get($cacheKey);

        return $pid && posix_kill($pid, 0) && $this->isFfmpeg($pid);
    }

    /**
     * Get the PID of the currently running HLS stream.
     *
     * @param  string  $type
     * @param  string  $id
     * @return int|null
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
        if (PHP_OS_FAMILY === 'Linux' && file_exists("/proc/{$pid}/cmdline")) {
            $cmdline = file_get_contents("/proc/{$pid}/cmdline");

            return $cmdline && (strpos($cmdline, 'ffmpeg') !== false);
        }

        if (PHP_OS_FAMILY === 'Darwin' || PHP_OS_FAMILY === 'BSD') {
            $output = [];
            exec("ps -p {$pid} -o command=", $output);

            return ! empty($output) && strpos($output[0], 'ffmpeg') !== false;
        }

        // Default fallback
        return posix_kill($pid, 0);
    }

    /**
     * Build the FFmpeg command for HLS streaming.
     *
     * @param  string  $type
     * @param  string  $id
     * @param  string|null  $passedUserAgent
     * @param  string  $streamUrl
     * @return string The complete FFmpeg command
     */
    private function buildCmd(
        string $type,
        string $id,
        ?string $passedUserAgent,
        string $streamUrl
    ): string {
        $settings = ProxyService::getStreamSettings();
        $customCommandTemplate = $settings['ffmpeg_custom_command_template'] ?? null;
        $usingCustomTemplate = ! empty($customCommandTemplate);

        $storageDir = Storage::disk('app')->path($type === 'episode' ? "hls/e/{$id}" : "hls/{$id}");
        File::ensureDirectoryExists($storageDir, 0o755);

        $m3uPlaylistPath = "{$storageDir}/stream.m3u8";
        $segmentPathTemplate = "{$storageDir}/segment_%03d.ts";
        $segmentListEntryPrefixValue = ($type === 'channel' ? "hls/{$id}/" : "hls/e/{$id}/");
        $graphFilePath = "{$storageDir}/ffmpeg-graph-{$id}.txt";

        $ffmpegPath = config('proxy.ffmpeg_path') ?: ($settings['ffmpeg_path'] ?? 'jellyfin-ffmpeg');
        $effectiveUserAgent = $passedUserAgent ?: ($settings['ffmpeg_user_agent'] ?? 'LibVLC/3.0.20');

        if ($usingCustomTemplate) {
            $cmd = $customCommandTemplate;

            $finalVideoCodec = ProxyService::determineVideoCodec(
                config('proxy.ffmpeg_codec_video', null),
                $settings['ffmpeg_codec_video'] ?? 'copy'
            );

            $hwaccelInitArgsValue = '';
            $hwaccelArgsValue = '';
            $videoFilterArgsValue = '';
            $codecSpecificOutputArgs = '';
            $outputVideoCodecForTemplate = $finalVideoCodec;

            $vaapiEnabled = (($settings['hardware_acceleration_method'] ?? 'none') === 'vaapi');
            if ($vaapiEnabled || str_contains($finalVideoCodec, '_vaapi')) {
                $outputVideoCodecForTemplate = str_contains($finalVideoCodec, '_vaapi') ? $finalVideoCodec : 'h264_vaapi';
                $vaapiDevice = escapeshellarg($settings['ffmpeg_vaapi_device'] ?? '/dev/dri/renderD128');
                $hwaccelInitArgsValue = "-init_hw_device vaapi=va_device:{$vaapiDevice} -filter_hw_device va_device";
                $hwaccelArgsValue = '-hwaccel vaapi -hwaccel_device va_device -hwaccel_output_format vaapi';
                if (! empty($settings['ffmpeg_vaapi_video_filter'])) {
                    $videoFilterArgsValue = '-vf '.escapeshellarg(trim($settings['ffmpeg_vaapi_video_filter'], "'\","));
                }
            } elseif ((($settings['hardware_acceleration_method'] ?? 'none') === 'qsv') || str_contains($finalVideoCodec, '_qsv')) {
                $outputVideoCodecForTemplate = str_contains($finalVideoCodec, '_qsv') ? $finalVideoCodec : 'h264_qsv';
                $qsvDevice = escapeshellarg($settings['ffmpeg_qsv_device'] ?? '/dev/dri/renderD128');
                $qsvDeviceName = 'qsv_hw';
                $hwaccelInitArgsValue = "-init_hw_device qsv={$qsvDeviceName}:{$qsvDevice}";
                $hwaccelArgsValue = "-hwaccel qsv -hwaccel_device {$qsvDeviceName} -hwaccel_output_format qsv";
                if (! empty($settings['ffmpeg_qsv_video_filter'])) {
                    $videoFilterArgsValue = '-vf '.escapeshellarg(trim($settings['ffmpeg_qsv_video_filter'], "'\","));
                }
                if (! empty($settings['ffmpeg_qsv_encoder_options'])) {
                    $codecSpecificOutputArgs = $settings['ffmpeg_qsv_encoder_options'];
                }
            }

            $audioCodecForTemplate = $settings['ffmpeg_codec_audio'] ?: (config('proxy.ffmpeg_codec_audio') ?: 'copy');
            $subtitleCodecForTemplate = $settings['ffmpeg_codec_subtitles'] ?: (config('proxy.ffmpeg_codec_subtitles') ?: 'copy');

            $outputCommandSegmentForTemplate = "-c:v {$outputVideoCodecForTemplate}".($codecSpecificOutputArgs ? " {$codecSpecificOutputArgs}" : '')." -c:a {$audioCodecForTemplate}"." -c:s {$subtitleCodecForTemplate}";
            $videoCodecArgsForTemplate = "-c:v {$outputVideoCodecForTemplate}".($codecSpecificOutputArgs ? " {$codecSpecificOutputArgs}" : '');
            $audioCodecArgsForTemplate = "-c:a {$audioCodecForTemplate}";
            $subtitleCodecArgsForTemplate = "-c:s {$subtitleCodecForTemplate}";

            $baseAdditionalArgs = config('proxy.ffmpeg_additional_args', '');
            $qsvAdditionalArgs = $settings['ffmpeg_qsv_additional_args'] ?? '';
            $combinedAdditionalArgs = !empty($qsvAdditionalArgs) ? trim($qsvAdditionalArgs) . ' ' . trim($baseAdditionalArgs) : trim($baseAdditionalArgs);

            $replacements = [
                '{FFMPEG_PATH}' => escapeshellcmd($ffmpegPath),
                '{INPUT_URL}' => escapeshellarg($streamUrl),
                '{USER_AGENT}' => escapeshellarg($effectiveUserAgent),
                '{REFERER}' => escapeshellarg('MyComputer'),
                '{HWACCEL_INIT_ARGS}' => trim($hwaccelInitArgsValue),
                '{HWACCEL_ARGS}' => trim($hwaccelArgsValue),
                '{VIDEO_FILTER_ARGS}' => trim($videoFilterArgsValue),
                '{OUTPUT_OPTIONS}' => trim($outputCommandSegmentForTemplate),
                '{VIDEO_CODEC_ARGS}' => trim($videoCodecArgsForTemplate),
                '{AUDIO_CODEC_ARGS}' => trim($audioCodecArgsForTemplate),
                '{SUBTITLE_CODEC_ARGS}' => trim($subtitleCodecArgsForTemplate),
                '{ADDITIONAL_ARGS}' => trim($combinedAdditionalArgs),
                '{M3U_PLAYLIST_PATH}' => escapeshellarg($m3uPlaylistPath),
                '{SEGMENT_PATH_TEMPLATE}' => escapeshellarg($segmentPathTemplate),
                '{SEGMENT_LIST_ENTRY_PREFIX}' => escapeshellarg($segmentListEntryPrefixValue),
                '{GRAPH_FILE_PATH}' => escapeshellarg($graphFilePath),
            ];
            
            return str_replace(array_keys($replacements), array_values($replacements), $cmd);

        } else {
            // --- Default command building logic ---
            $cmdParts = [];
            $cmdParts[] = escapeshellcmd($ffmpegPath);

            $finalVideoCodec = ProxyService::determineVideoCodec(
                config('proxy.ffmpeg_codec_video', null),
                $settings['ffmpeg_codec_video'] ?? 'copy'
            );

            $userArgs = config('proxy.ffmpeg_additional_args', '');

            if ((($settings['hardware_acceleration_method'] ?? 'none') === 'vaapi') || str_contains($finalVideoCodec, '_vaapi')) {
                $outputVideoCodec = str_contains($finalVideoCodec, '_vaapi') ? $finalVideoCodec : 'h264_vaapi';
                $vaapiDevice = escapeshellarg($settings['ffmpeg_vaapi_device'] ?? '/dev/dri/renderD128');
                $cmdParts[] = "-init_hw_device vaapi=va_device:{$vaapiDevice} -filter_hw_device va_device";
                $cmdParts[] = '-hwaccel vaapi -hwaccel_device va_device -hwaccel_output_format vaapi';
                if (! empty($settings['ffmpeg_vaapi_video_filter'])) {
                    $cmdParts[] = "-vf '".trim($settings['ffmpeg_vaapi_video_filter'], "'")."'";
                }
            } elseif ((($settings['hardware_acceleration_method'] ?? 'none') === 'qsv') || str_contains($finalVideoCodec, '_qsv')) {
                $outputVideoCodec = str_contains($finalVideoCodec, '_qsv') ? $finalVideoCodec : 'h264_qsv';
                $qsvDevice = escapeshellarg($settings['ffmpeg_qsv_device'] ?? '/dev/dri/renderD128');
                $cmdParts[] = "-init_hw_device qsv=qsv_hw:{$qsvDevice}";
                $cmdParts[] = '-hwaccel qsv -hwaccel_device qsv_hw -hwaccel_output_format qsv';
                $cmdParts[] = "-vf 'hwupload=extra_hw_frames=64,scale_qsv=format=nv12'";
                $codecSpecificArgs = $settings['ffmpeg_qsv_encoder_options'] ?? '-preset medium -global_quality 23';
                $qsvAdditionalArgs = $settings['ffmpeg_qsv_additional_args'] ?? '';
                if (!empty($qsvAdditionalArgs)) {
                    $userArgs = trim($qsvAdditionalArgs) . ($userArgs ? ' ' . $userArgs : '');
                }
            } else {
                $outputVideoCodec = $finalVideoCodec;
                $codecSpecificArgs = '';
            }
            
            $cmdParts = array_merge($cmdParts, [
                '-fflags nobuffer+igndts',
                '-flags low_delay',
                '-avoid_negative_ts disabled',
                '-analyzeduration 1M',
                '-probesize 1M',
                '-max_delay 500000',
                '-fpsprobesize 0',
                '-err_detect ignore_err',
                '-ignore_unknown',
                '-user_agent '.escapeshellarg($effectiveUserAgent),
                '-referer "MyComputer"',
                '-multiple_requests 1',
                '-reconnect_on_network_error 1',
                '-reconnect_on_http_error 5xx,4xx,509',
                '-reconnect_streamed 1',
                '-reconnect_delay_max 2',
                '-noautorotate',
            ]);

            if (! empty(trim($userArgs))) $cmdParts[] = trim($userArgs);
            $cmdParts[] = '-i '.escapeshellarg($streamUrl);
            $cmdParts[] = "-c:v {$outputVideoCodec}";
            if (! empty($codecSpecificArgs)) $cmdParts[] = $codecSpecificArgs;
            $cmdParts[] = '-c:a '.($settings['ffmpeg_codec_audio'] ?: (config('proxy.ffmpeg_codec_audio') ?: 'copy'));
            $cmdParts[] = '-c:s '.($settings['ffmpeg_codec_subtitles'] ?: (config('proxy.ffmpeg_codec_subtitles') ?: 'copy'));
            $cmdParts[] = '-vsync cfr';

            $segmentBaseUrlForDefaultHls = ($type === 'channel' ? url("/api/stream/{$id}") . '/' : url("/api/stream/e/{$id}") . '/');
            $hlsTime = $settings['ffmpeg_hls_time'] ?? 4;

            $cmdParts = array_merge($cmdParts, [
                '-f hls',
                "-hls_time {$hlsTime}",
                '-hls_list_size 15',
                '-hls_flags delete_segments+append_list+split_by_time',
                '-use_wallclock_as_timestamps 1',
                '-start_number 0',
                '-hls_allow_cache 0',
                '-hls_segment_type mpegts',
                '-hls_segment_filename '.escapeshellarg($segmentPathTemplate),
                '-hls_base_url '.escapeshellarg($segmentBaseUrlForDefaultHls),
                escapeshellarg($m3uPlaylistPath),
            ]);

            if ($settings['ffmpeg_debug'] ?? false) {
                $cmdParts[] = '-loglevel verbose';
            } else {
                $cmdParts[] = '-hide_banner -nostats -loglevel error';
            }
            return implode(' ', $cmdParts);
        }
    }
    
    /**
     * Attempts to start a single, specific stream source.
     */
    public function attemptSpecificStreamSource(
        string $type,
        $specificStreamModel,
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

        Log::channel('ffmpeg')->info("[SpecificAttempt] OrigReq ID {$originalModelId} - Attempting: {$type} ID {$streamIdToAttempt} ('{$currentStreamTitleAttempt}').");

        $playlist = $specificStreamModel->playlist;
        if (! $playlist || $playlist->id !== $playlistIdOfSpecificStream) {
            Log::channel('ffmpeg')->warning("[SpecificAttempt] OrigReq ID {$originalModelId} - Playlist ID mismatch or not found for {$type} ID {$streamIdToAttempt}. Using provided playlist ID {$playlistIdOfSpecificStream}. Model playlist: ".(isset($playlist->id) ? $playlist->id : 'N/A'));
        }

        $streamSettings = ProxyService::getStreamSettings();
        $ffprobeTimeout = $streamSettings['ffmpeg_ffprobe_timeout'] ?? 5;
        $availableStreamsCount = $playlist->available_streams ?? 1;
        $activeStreams = $this->incrementActiveStreams($playlistIdOfSpecificStream);

        if ($this->wouldExceedStreamLimit($playlistIdOfSpecificStream, $availableStreamsCount, $activeStreams)) {
            $this->decrementActiveStreams($playlistIdOfSpecificStream);
            Log::channel('ffmpeg')->warning("[SpecificAttempt] OrigReq ID {$originalModelId} - Max streams for playlist ID {$playlistIdOfSpecificStream}. Cannot start {$type} ID {$streamIdToAttempt}.");
            return null;
        }

        $streamUrl = '';
        if ($type === 'channel' && $specificStreamModel instanceof \App\Models\Channel) {
            $streamUrl = ($specificStreamModel->url_custom ?? $specificStreamModel->url);
        } elseif ($type === 'episode' && $specificStreamModel instanceof \App\Models\Episode) {
            $streamUrl = $specificStreamModel->url;
        }
        $userAgent = $playlist->user_agent ?? null;

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

            Log::channel('ffmpeg')->debug("[SpecificAttempt] OrigReq ID {$originalModelId} - Successfully started: {$type} ID {$streamIdToAttempt} ('{$currentStreamTitleAttempt}').");

            Cache::forget("hls:monitoring_disabled:{$type}:{$streamIdToAttempt}");

            MonitorStreamHealthJob::dispatch(
                $type,
                $streamIdToAttempt,
                $originalModelId,
                $originalModelTitle,
                $playlistIdOfSpecificStream,
                $streamSourceIds,
                $newCurrentIndexInSourceIds
            )->delay(now()->addSeconds(config('streaming.monitor_job_interval_seconds', 10)));

            Log::channel('ffmpeg')->info("[SpecificAttempt] OrigReq ID {$originalModelId} - Dispatched MonitorStreamHealthJob for {$type} ID {$streamIdToAttempt} (Index {$newCurrentIndexInSourceIds}).");

            return $specificStreamModel;
        } catch (SourceNotResponding $e) {
            $this->decrementActiveStreams($playlistIdOfSpecificStream);
            Log::channel('ffmpeg')->error("[SpecificAttempt] OrigReq ID {$originalModelId} - SourceNotResponding for {$type} ID {$streamIdToAttempt} ('{$currentStreamTitleAttempt}'): ".$e->getMessage());
            return null;
        } catch (Exception $e) {
            $this->decrementActiveStreams($playlistIdOfSpecificStream);
            Log::channel('ffmpeg')->error("[SpecificAttempt] OrigReq ID {$originalModelId} - Exception for {$type} ID {$streamIdToAttempt} ('{$currentStreamTitleAttempt}'): ".$e->getMessage());
            return null;
        }
    }
}
