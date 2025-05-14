<?php

namespace App\Http\Controllers;

use Exception;
use App\Models\Channel;
use App\Services\HlsStreamService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

class ChannelHlsStreamController extends Controller
{
    private $hlsService;

    public function __construct(HlsStreamService $hlsStreamService)
    {
        $this->hlsService = $hlsStreamService;
    }

    /**
     * Launch (or re-launch) an FFmpeg HLS job for this channel,
     * then redirect clients to the static .m3u8 URL.
     * 
     * @param Request $request
     * @param int|string $id
     * 
     * @return \Illuminate\Http\Response
     */
    public function __invoke(Request $request, $id)
    {
        // Find the channel by ID
        if (strpos($id, '==') === false) {
            $id .= '=='; // right pad to ensure proper decoding
        }
        $channel = Channel::findOrFail(base64_decode($id));
        $title = $channel->title_custom ?? $channel->title;
        $title = strip_tags($title);
        $streamUrl = $channel->url_custom ?? $channel->url;

        if (!$this->hlsService->isRunning($channel->id)) {
            try {
                $this->hlsService->startStream($channel->id, $streamUrl);
                Log::channel('ffmpeg')->info("Started HLS stream for channel {$channel->id} ({$title})");
            } catch (Exception $e) {
                Log::channel('ffmpeg')->error("Failed to start HLS stream for channel {$channel->id} ({$title}): {$e->getMessage()}");
                abort(500, 'Failed to start the stream.');
            }
        } else {
            Log::channel('ffmpeg')->info("HLS stream already running for channel {$channel->id} ({$title})");
        }

        // Return the Playlist
        $pid = Cache::get("hls:pid:{$channel->id}");
        $path = Storage::disk('app')->path("hls/{$id}/stream.m3u8");
        $maxAttempts = 10;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            // If the playlist is ready, serve it immediately
            if (file_exists($path)) {
                return response()->stream(function ($path) {
                    echo file_get_contents($path);
                }, 200, [
                    'Content-Type' => 'application/vnd.apple.mpegurl',
                    'Cache-Control' => 'no-cache, no-transform',
                    'Connection' => 'keep-alive',
                ]);
            }

            // On the last try, give up if FFmpeg isn’t running
            if ($attempt === $maxAttempts) {
                if (!$pid || !posix_kill($pid, 0)) {
                    Log::channel('ffmpeg')
                        ->error("FFmpeg process {$pid} is not running (or died) for channel {$id}");
                    abort(404, 'Playlist not found.');
                }

                // If it *is* running but playlist never appeared, tell the client to retry
                return redirect()
                    ->route('stream.hls.playlist', ['id' => $id])
                    ->with('error', 'Playlist not ready yet. Please try again.');
            }

            // Otherwise, wait and retry
            sleep(1);
        }
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
        $path = Storage::disk('app')->path("hls/{$id}/{$segment}");

        //abort_unless(file_exists($path), 404, 'Segment not found.');
        // If segment is not found, don't 404 as it will disconnect the stream, return empty 200 response
        if (!file_exists($path)) {
            return response('', 200);
        }

        // Record timestamp in Redis (never expires until we prune)
        Redis::set("hls:last_seen:{$id}", now()->timestamp);

        // Add to active IDs set
        Redis::sadd('hls:active_ids', $id);

        return response()->stream(function () use ($path) {
            echo file_get_contents($path);
        }, 200, [
            'Content-Type' => 'video/mp2t',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
        ]);
    }

    /**
     * Return true if $pid is alive and matches an ffmpeg command.
     */
    protected function isFfmpeg(int $pid): bool
    {
        $cmdlinePath = "/proc/{$pid}/cmdline";
        if (! file_exists($cmdlinePath)) {
            return false;
        }

        $cmd = @file_get_contents($cmdlinePath);
        // FFmpeg’s binary name should appear first
        return $cmd && strpos($cmd, 'ffmpeg') !== false;
    }
}
