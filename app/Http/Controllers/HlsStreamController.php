<?php

namespace App\Http\Controllers;

use Exception;
use App\Models\Channel;
use App\Models\Episode;
use App\Services\HlsStreamService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

/**
 * Class HlsStreamController
 * 
 * This controller handles the HLS streaming for channels.
 * It manages the starting of the FFmpeg process and serves the HLS playlist and segments.
 * 
 * NOTE: Using NGINX internal redirects for serving the playlist and segments.
 *       If running locally, make sure to set up NGINX with the following configuration:
 * 
 * location /internal/hls/ {
 *     internal;
 *     alias [PROJECT_ROOT_PATH]/storage/app/hls/;
 *     access_log off;
 *     add_header Cache-Control no-cache;
 * }
 * 
 */
class HlsStreamController extends Controller
{
    private $hlsService;

    public function __construct(HlsStreamService $hlsStreamService)
    {
        $this->hlsService = $hlsStreamService;
    }

    /**
     * Launch (or re-launch) an FFmpeg HLS job for this channel,
     * then send the contents of the .m3u8 file.
     * 
     * @param Request $request
     * @param int|string $encodedId
     * 
     * @return \Illuminate\Http\Response
     */
    public function serveChannelPlaylist(Request $request, $encodedId)
    {
        // Find the channel by ID
        if (strpos($encodedId, '==') === false) {
            $encodedId .= '=='; // right pad to ensure proper decoding
        }
        $channel = Channel::findOrFail(base64_decode($encodedId));
        $channelId = $channel->id;
        $title = $channel->title_custom ?? $channel->title;

        // Start stream, if not already running
        if (!$this->hlsService->isRunning(type: 'channel', id: $channelId)) {
            $streamUrl = $channel->url_custom ?? $channel->url;
            $title = strip_tags($title);
            $playlist = $channel->playlist;
            try {
                $this->hlsService->startStream(
                    type: 'channel',
                    id: $channelId,
                    streamUrl: $streamUrl,
                    title: $title,
                    userAgent: $playlist->user_agent ?? null,
                );
                Log::channel('ffmpeg')->info("Started HLS stream for channel {$channelId} ({$title})");
            } catch (Exception $e) {
                Log::channel('ffmpeg')->error("Failed to start HLS stream for channel {$channelId} ({$title}): {$e->getMessage()}");
                abort(500, 'Failed to start the stream.');
            }
        } else {
            // Stream is already running, no need to start it again
            // Log::channel('ffmpeg')->info("HLS stream already running for channel {$channelId} ({$title})");
        }

        // Return the Playlist
        $pid = Cache::get("hls:pid:channel:{$channelId}");
        $path = Storage::disk('app')->path("hls/{$channelId}/stream.m3u8");
        $maxAttempts = 10;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            // If the playlist is ready, serve it immediately
            if (file_exists($path)) {
                return response('', 200, [
                    'Content-Type'      => 'application/vnd.apple.mpegurl',
                    'X-Accel-Redirect'  => "/internal/hls/{$channelId}/stream.m3u8",
                    'Cache-Control'     => 'no-cache, no-transform',
                    'Connection'        => 'keep-alive',
                ]);
            }

            // On the last try, give up if FFmpeg isn’t running
            if ($attempt === $maxAttempts) {
                if (!$pid || !posix_kill($pid, 0)) {
                    Log::channel('ffmpeg')
                        ->error("FFmpeg process {$pid} is not running (or died) for channel {$channelId}");
                    abort(404, 'Playlist not found.');
                }

                // If it *is* running but playlist never appeared, tell the client to retry
                return redirect()
                    ->route('stream.hls.playlist', ['encodedId' => $encodedId])
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
     * @param int|string $channelId
     * 
     * @return \Illuminate\Http\Response
     */
    public function serveChannelSegment(Request $request, $channelId, $segment)
    {
        $path = Storage::disk('app')->path("hls/{$channelId}/{$segment}");

        // If segment is not found, return 404 error
        abort_unless(file_exists($path), 404, 'Segment not found.');

        // Record timestamp in Redis (never expires until we prune)
        Redis::set("hls:channel_last_seen:{$channelId}", now()->timestamp);

        // Add to active IDs set
        Redis::sadd('hls:active_channel_ids', $channelId);

        return response('', 200, [
            'Content-Type'     => 'video/MP2T',
            'X-Accel-Redirect' => "/internal/hls/{$channelId}/{$segment}",
            'Cache-Control'    => 'no-cache, no-transform',
            'Connection'       => 'keep-alive',
        ]);
    }

    /**
     * Launch (or re-launch) an FFmpeg HLS job for this episode,
     * then send the contents of the .m3u8 file.
     * 
     * @param Request $request
     * @param int|string $encodedId
     * 
     * @return \Illuminate\Http\Response
     */
    public function serveEpisodePlaylist(Request $request, $encodedId)
    {
        // Find the channel by ID
        if (strpos($encodedId, '==') === false) {
            $encodedId .= '=='; // right pad to ensure proper decoding
        }
        $episode = Episode::findOrFail(base64_decode($encodedId));
        $episodeId = $episode->id;
        $title = $episode->title;

        // Start stream, if not already running
        if (!$this->hlsService->isRunning(type: 'episode', id: $episodeId)) {
            $streamUrl = $episode->url;
            $title = strip_tags($title);
            $playlist = $episode->playlist;
            try {
                $this->hlsService->startStream(
                    type: 'episode',
                    id: $episodeId,
                    streamUrl: $streamUrl,
                    title: $title,
                    userAgent: $playlist->user_agent ?? null,
                );
                Log::channel('ffmpeg')->info("Started HLS stream for episode {$episodeId} ({$title})");
            } catch (Exception $e) {
                Log::channel('ffmpeg')->error("Failed to start HLS stream for episode {$episodeId} ({$title}): {$e->getMessage()}");
                abort(500, 'Failed to start the stream.');
            }
        } else {
            // Stream is already running, no need to start it again
            // Log::channel('ffmpeg')->info("HLS stream already running for channel {$channelId} ({$title})");
        }

        // Return the Playlist
        $pid = Cache::get("hls:pid:episode:{$episodeId}");
        $path = Storage::disk('app')->path("hls/e/{$episodeId}/stream.m3u8");
        $maxAttempts = 10;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            // If the playlist is ready, serve it immediately
            if (file_exists($path)) {
                return response('', 200, [
                    'Content-Type'      => 'application/vnd.apple.mpegurl',
                    'X-Accel-Redirect'  => "/internal/hls/e/{$episodeId}/stream.m3u8",
                    'Cache-Control'     => 'no-cache, no-transform',
                    'Connection'        => 'keep-alive',
                ]);
            }

            // On the last try, give up if FFmpeg isn’t running
            if ($attempt === $maxAttempts) {
                if (!$pid || !posix_kill($pid, 0)) {
                    Log::channel('ffmpeg')
                        ->error("FFmpeg process {$pid} is not running (or died) for episode {$episodeId}");
                    abort(404, 'Playlist not found.');
                }

                // If it *is* running but playlist never appeared, tell the client to retry
                return redirect()
                    ->route('stream.hls.episode', ['encodedId' => $encodedId])
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
     * @param int|string $episodeId
     * 
     * @return \Illuminate\Http\Response
     */
    public function serveEpisodeSegment(Request $request, $episodeId, $segment)
    {
        $path = Storage::disk('app')->path("hls/e/{$episodeId}/{$segment}");

        // If segment is not found, return 404 error
        abort_unless(file_exists($path), 404, 'Segment not found.');

        // Record timestamp in Redis (never expires until we prune)
        Redis::set("hls:episode:last_seen:{$episodeId}", now()->timestamp);

        // Add to active IDs set
        Redis::sadd('hls:active_episode_ids', $episodeId);

        return response('', 200, [
            'Content-Type'     => 'video/MP2T',
            'X-Accel-Redirect' => "/internal/hls/e/{$episodeId}/{$segment}",
            'Cache-Control'    => 'no-cache, no-transform',
            'Connection'       => 'keep-alive',
        ]);
    }
}
