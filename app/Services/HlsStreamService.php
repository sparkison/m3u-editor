<?php

namespace App\Services;

use Exception;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process as SymphonyProcess;

class HlsStreamService
{
    /**
     * Start an HLS stream for the given channel.
     *
     * @param string $id
     * @param string $streamUrl
     * @return int The FFmpeg process ID
     */
    public function startStream($id, $streamUrl): int
    {
        // Only start one FFmpeg per channel at a time
        $cacheKey = "hls:pid:{$id}";
        $pid = Cache::get($cacheKey);
        if (!($this->isRunning($id))) {
            // Get user preferences
            $userPreferences = app(GeneralSettings::class);
            $settings = [
                'ffmpeg_debug' => false,
                'ffmpeg_max_tries' => 3,
                'ffmpeg_user_agent' => 'VLC/3.0.21 LibVLC/3.0.21',
            ];
            try {
                $settings = [
                    'ffmpeg_debug' => $userPreferences->ffmpeg_debug ?? $settings['ffmpeg_debug'],
                    'ffmpeg_max_tries' => $userPreferences->ffmpeg_max_tries ?? $settings['ffmpeg_max_tries'],
                    'ffmpeg_user_agent' => $userPreferences->ffmpeg_user_agent ?? $settings['ffmpeg_user_agent'],
                ];
            } catch (Exception $e) {
                // Ignore
            }

            // Get user agent
            $userAgent = escapeshellarg($settings['ffmpeg_user_agent']);

            // Get user defined options
            $userArgs = config('proxy.ffmpeg_additional_args', '');
            if (!empty($userArgs)) {
                $userArgs .= ' ';
            }

            // Setup the stream file paths
            $storageDir = Storage::disk('app')->path("hls/{$id}");
            File::ensureDirectoryExists($storageDir, 0755);

            // Setup the stream URL
            $playlist = "{$storageDir}/stream.m3u8";
            $segment = "{$storageDir}/segment_%03d.ts";
            $segmentBaseUrl = url("/api/stream/{$id}") . '/';

            $cmd = sprintf(
                'ffmpeg ' .
                    // Optimization options:
                    '-fflags nobuffer -flags low_delay ' .

                    // Pre-input HTTP options:
                    '-user_agent "%s" -referer "MyComputer" ' .
                    '-multiple_requests 1 -reconnect_on_network_error 1 ' .
                    '-reconnect_on_http_error 5xx,4xx -reconnect_streamed 1 ' .
                    '-reconnect_delay_max 5 -noautorotate ' .

                    // User defined options:
                    '%s' .

                    // Input:
                    '-re -i "%s" ' .

                    // Progress tracking:
                    '-progress pipe:2 ' .

                    // Output options:
                    '-preset veryfast -g 15 -keyint_min 15 -sc_threshold 0 ' .
                    '%s ' . // output format

                    // HLS options:
                    '-f hls -hls_time 2 -hls_list_size 6 ' .
                    '-hls_flags delete_segments+append_list+independent_segments ' .
                    '-use_wallclock_as_timestamps 1 ' .
                    '-hls_segment_type fmp4 ' .
                    '-hls_segment_filename %s ' .
                    '-hls_base_url %s %s ' .

                    // Logging:
                    '%s',
                $userAgent,                   // for -user_agent
                $userArgs,                    // user defined options
                $streamUrl,                   // input URL
                $segment,                     // segment filename 
                $segmentBaseUrl,              // base URL for segments (want to make sure routed through the proxy to track active users)
                $playlist,                    // playlist filename
                $settings['ffmpeg_debug'] ? '' : '-hide_banner -nostats -loglevel error'
            );

            $process = SymphonyProcess::fromShellCommandline($cmd);
            $process->setTimeout(null);
            $process->start();
            if (!$process->isRunning()) {
                Log::channel('ffmpeg')->error("Failed to launch FFmpeg for channel {$id}");
                abort(500, 'Could not start stream.');
            }

            // Start in non‑blocking mode *with* a callback for both STDOUT & STDERR
            $process->start(function (int $type, string $buffer) use ($id) {
                // Split incoming chunks into individual lines
                $lines = preg_split('/\r?\n/', trim($buffer)) ?: [];
                foreach ($lines as $line) {
                    // Only concerned with STDERR (ffmpeg's progress output and errors)
                    if ($type === SymphonyProcess::ERR) {
                        // "progress" lines are always KEY=VALUE
                        if (strpos($line, '=') !== false && preg_match('/^[a-z_]+=/', $line)) {
                            list($key, $value) = explode('=', $line, 2);

                            // push the metric value onto a Redis list and trim to last 20 points
                            $listKey = "hls:hist:{$id}:{$key}";
                            $timeKey = "hls:hist:{$id}:timestamps";

                            // push the timestamp into a parallel list (once per loop)
                            Redis::rpush($timeKey, now()->format('H:i:s'));
                            Redis::ltrim($timeKey, -20, -1);

                            // push the metric value
                            Redis::rpush($listKey, $value);
                            Redis::ltrim($listKey, -20, -1);
                        } elseif ($line !== '') {
                            // anything else is a true ffmpeg log/error
                            Log::channel('ffmpeg')->error($line);
                        }
                    }
                }
            });

            // Immediately after start
            if (! $process->isRunning()) {
                Log::channel('ffmpeg')->error("Failed to launch FFmpeg for HLS stream {$id}");
                abort(500, 'Could not start HLS stream.');
            }

            // Cache the PID, mark as active, etc.
            $pid = $process->getPid();
            Cache::forever("hls:pid:{$id}", $pid);
            Redis::set("hls:last_seen:{$id}", now()->timestamp);
            Redis::sadd('hls:active_ids', $id);

            /*
                // Tell proc_open to give us back a stderr pipe
                $descriptors = [
                    0 => ['pipe', 'r'], // stdin (we won't use)
                    1 => ['pipe', 'w'], // stdout (we won't use)
                    2 => ['pipe', 'w'], // stderr (we will log)
                ];
                $pipes = [];
                $process = proc_open($cmd, $descriptors, $pipes);

                if (!is_resource($process)) {
                    Log::channel('ffmpeg')->error("Failed to launch FFmpeg for channel {$id}");
                    abort(500, 'Could not start stream.');
                }

                // Immediately close stdin/stdout
                fclose($pipes[0]);
                fclose($pipes[1]);

                // Make stderr non-blocking
                stream_set_blocking($pipes[2], false);

                // Spawn a little "reader" that pulls from stderr and logs
                $logger = Log::channel('ffmpeg');
                $stderr = $pipes[2];

                // Register shutdown function to ensure the pipe is drained
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

                // Cache the actual FFmpeg PID
                $status = proc_get_status($process);
                $pid = $status['pid'];

                Cache::forever("hls:pid:{$id}", $pid);

                // Record timestamp in Redis (never expires until we prune)
                Redis::set("hls:last_seen:{$id}", now()->timestamp);

                // Add to active IDs set
                Redis::sadd('hls:active_ids', $id);
            */
        }
        return $pid;
    }

    /**
     * Stop FFmpeg for the given HLS stream channel (if currently running).
     *
     * @param string $id
     * @return bool
     */
    public function stopStream($id): bool
    {
        $cacheKey = "hls:pid:{$id}";
        $pid = Cache::get($cacheKey);
        $wasRunning = false;
        if ($this->isRunning($id)) {
            $wasRunning = true;
            // Attempt to gracefully stop the FFmpeg process
            posix_kill($pid, SIGTERM);
            sleep(1);
            if (posix_kill($pid, 0)) {
                // If the process is still running after SIGTERM, force kill it
                posix_kill($pid, SIGKILL);
            }
            Cache::forget($cacheKey);

            // Cleanup on-disk HLS files
            $storageDir = Storage::disk('app')->path("hls/{$id}");
            File::deleteDirectory($storageDir);
        } else {
            Log::channel('ffmpeg')->warning("No running FFmpeg process for channel {$id} to stop.");
        }

        // Remove from active IDs set
        Redis::srem('hls:active_ids', $id);

        return $wasRunning;
    }

    /**
     * Check if an HLS stream is currently running for the given channel ID.
     *
     * @param string $id
     * @return bool
     */
    public function isRunning($id): bool
    {
        $cacheKey = "hls:pid:{$id}";
        $pid = Cache::get($cacheKey);
        return $pid && posix_kill($pid, 0) && $this->isFfmpeg($pid);
    }

    /**
     * Get the PID of the currently running HLS stream for the given channel ID.
     *
     * @param string $id
     * @return bool
     */
    public function getPid($id): ?int
    {
        $cacheKey = "hls:pid:{$id}";
        return Cache::get($cacheKey);
    }

    /**
     * Return true if $pid is alive and matches an ffmpeg command.
     */
    protected function isFfmpeg(int $pid): bool
    {
        return true;

        // TODO: This is a placeholder for the actual implementation.
        //       Currently not working, seems like the process is not flagged correctly.
        //       Need to do some more investigation.
        $cmdlinePath = "/proc/{$pid}/cmdline";
        if (! file_exists($cmdlinePath)) {
            return false;
        }

        $cmd = @file_get_contents($cmdlinePath);
        // FFmpeg’s binary name should appear first
        return $cmd && strpos($cmd, 'ffmpeg') !== false;
    }
}
