<?php

namespace App\Http\Controllers;

use Exception;
use App\Models\Channel;
use App\Settings\GeneralSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\Process as SymphonyProcess;
// use Spatie\TemporaryDirectory\TemporaryDirectory;

class ChannelStreamController extends Controller
{
    /**
     * Stream an IPTV channel.
     *
     * @param Request $request
     * @param int|string $id
     *
     * @return StreamedResponse
     */
    public function __invoke(Request $request, $id)
    {
        // Prevent timeouts, etc.
        ini_set('max_execution_time', 0);
        ini_set('output_buffering', 'off');
        ini_set('implicit_flush', 1);

        // Find the channel by ID
        if (strpos($id, '==') === false) {
            $id .= '=='; // right pad to ensure proper decoding
        }
        $channel = Channel::findOrFail(base64_decode($id));
        $title = $channel->title_custom ?? $channel->title;
        $title = strip_tags($title);

        // Setup streams array
        $streamUrls = [
            $channel->url_custom ?? $channel->url
            // leave this here for future use...
            // @TODO: implement ability to assign fallback channels
        ];

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
        return new StreamedResponse(function () use ($streamUrls, $title, $settings) {
            while (ob_get_level()) {
                ob_end_flush();
            }
            flush();

            // Disable output buffering to ensure real-time streaming
            ini_set('zlib.output_compression', 0);

            // Get user agent
            $userAgent = $settings['ffmpeg_user_agent'];
            $maxRetries = $settings['ffmpeg_max_tries'];

            // Loop through available streams...
            foreach ($streamUrls as $streamUrl) {
                $cmd = sprintf(
                    'ffmpeg ' .
                        // Pre-input HTTP options:
                        '-user_agent "%s" -referer "MyComputer" ' .
                        '-multiple_requests 1 -reconnect_on_network_error 1 ' .
                        '-reconnect_on_http_error 5xx,4xx -reconnect_streamed 1 ' .
                        '-reconnect_delay_max 5 -noautorotate ' .

                        // I/O options:
                        '-re -i "%s" ' .
                        '-c copy -f mpegts pipe:1 ' .

                        // Logging:
                        '%s',
                    $userAgent,                   // for -user_agent
                    $streamUrl,                   // input URL
                    $settings['ffmpeg_debug'] ? '' : '-hide_banner -nostats -loglevel error'
                );

                // Continue trying until the client disconnects, or max retries are reached
                $retries = 0;
                while (!connection_aborted()) {
                    $process = SymphonyProcess::fromShellCommandline($cmd);
                    $process->setTimeout(null);
                    try {
                        $process->run(function ($type, $buffer) {
                            if (connection_aborted()) {
                                throw new \Exception("Connection aborted by client.");
                            }
                            if ($type === SymphonyProcess::OUT) {
                                echo $buffer;
                                flush();
                                usleep(10000); // Reduce CPU usage
                            }
                            if ($type === SymphonyProcess::ERR) {
                                Log::channel('ffmpeg')->error($buffer);
                            }
                        });
                    } catch (\Exception $e) {
                        // Log eror and attempt to reconnect.
                        if (!connection_aborted()) {
                            Log::channel('ffmpeg')
                                ->error("Error streaming channel (\"$title\"): " . $e->getMessage());
                        }
                    }
                    // If we get here, the process ended.
                    if (connection_aborted()) {
                        if ($process->isRunning()) {
                            $process->stop(1); // SIGTERM then SIGKILL
                        }
                        return;
                    }
                    if (++$retries >= $maxRetries) {
                        // Log error and stop trying this stream...
                        Log::channel('ffmpeg')
                            ->error("FFmpeg error: max retries of $maxRetries reached for stream for channel $title.");

                        // ...break and try the next stream
                        break;
                    }
                    // Wait a short period before trying to reconnect.
                    sleep(min(8, $retries));
                }
            }

            echo "Error: No available streams.";
        }, 200, [
            'Content-Type' => 'video/mp2t',
            'Connection' => 'keep-alive',
            'Cache-Control' => 'no-store, no-transform',
            'Content-Disposition' => "inline; filename=\"stream.ts\"",
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Launch (or re-launch) an FFmpeg HLS job for this channel,
     * then redirect clients to the static .m3u8 URL.
     * 
     * @param Request $request
     * @param int|string $id
     * 
     * @return \Illuminate\Http\RedirectResponse
     */
    public function startHls(Request $request, $id)
    {
        // Find the channel by ID
        if (strpos($id, '==') === false) {
            $id .= '=='; // right pad to ensure proper decoding
        }
        $channel = Channel::findOrFail(base64_decode($id));
        $title = $channel->title_custom ?? $channel->title;
        $title = strip_tags($title);
        $streamUrl = $channel->url_custom ?? $channel->url;

        $storageDir = storage_path("app/hls/{$channel->id}");
        Storage::makeDirectory($storageDir);

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
        $userAgent = $settings['ffmpeg_user_agent'];

        // Only start one FFmpeg per channel at a time
        $cacheKey = "hls:pid:{$channel->id}";
        $existingPid = Cache::get($cacheKey);
        if (! $existingPid || ! posix_kill($existingPid, 0)) {
            $playlist = "{$storageDir}/stream.m3u8";
            $segment = "{$storageDir}/segment_%03d.ts";

            $cmd = sprintf(
                'ffmpeg ' .
                    // Pre-input HTTP options:
                    '-user_agent "%s" -referer "MyComputer" ' .
                    '-multiple_requests 1 -reconnect_on_network_error 1 ' .
                    '-reconnect_on_http_error 5xx,4xx -reconnect_streamed 1 ' .
                    '-reconnect_delay_max 5 -noautorotate ' .

                    // I/O options:
                    '-re -i "%s" ' .
                    '-c:v libx264 -preset veryfast -g 48 -sc_threshold 0 ' .
                    '-c:a aac -f hls -hls_time 4 -hls_list_size 6 ' .
                    '-hls_flags delete_segments+append_list ' .
                    '-hls_segment_filename %s %s ' .

                    // Logging:
                    '%s',
                $userAgent,                   // for -user_agent
                $streamUrl,                   // input URL
                $segment,                     // segment filename 
                $playlist,                    // playlist filename
                $settings['ffmpeg_debug'] ? '' : '-hide_banner -nostats -loglevel error'
            );

            $process = SymphonyProcess::fromShellCommandline($cmd);
            $process->setTimeout(null)->start();

            // Keep the Process object in cache so we can stop it later if needed
            Cache::forever($cacheKey, $process->getPid());
        }

        // Now redirect the client to the playlist URL
        return redirect()->route('stream.hls.playlist', ['id' => $channel->id]);
    }

    /**
     * Serve the generated playlist as a static file.
     * 
     * @param Request $request
     * @param int|string $id
     * 
     * @return \Illuminate\Http\Response
     */
    public function servePlaylist(Request $request, $id)
    {
        $cacheKeyPid = "hls:pid:{$id}";
        $pid = Cache::get($cacheKeyPid);
        $path = storage_path("app/hls/{$id}/stream.m3u8");

        // If FFmpeg is still running but playlist isn’t ready
        if ($pid && posix_kill($pid, 0) && ! file_exists($path)) {
            return response()->json([
                'status'  => 'processing',
                'message' => 'Playlist is being generated; try again shortly.',
            ], 202);
        }

        // Otherwise, 404 if no playlist
        if (! file_exists($path)) {
            Log::channel('ffmpeg')->error("HLS playlist missing for channel {$id}");
            abort(404, 'Playlist not found.');
        }

        return response()->file($path, [
            'Content-Type' => 'application/vnd.apple.mpegurl',
        ]);
    }

    /**
     * Serve individual .ts segments.
     * 
     * @param Request $request
     * @param int|string $id
     * 
     * @return \Illuminate\Http\Response
     */
    public function serveSegment(Request $request, $id, $segment)
    {
        $path = storage_path("app/hls/{$id}/{$segment}");
        abort_unless(file_exists($path), 404, 'Segment not found.');

        // Update last-seen timestamp
        Cache::put("hls:last_seen:{$id}", now(), now()->addMinutes(10));

        // Use a Redis set or tagged cache to track active IDs
        Cache::tags('hls_active')->add($id, true, now()->addMinutes(10));

        return response()->file($path, [
            'Content-Type' => 'video/MP2T',
        ]);
    }
}
