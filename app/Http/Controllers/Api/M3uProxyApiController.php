<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Services\M3uProxyService;
use App\Services\PlayerTranscodingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class M3uProxyApiController extends Controller
{
    /**
     * Get the proxied URL for a channel and redirect
     * 
     * @param  Request  $request
     * @param  int  $id
     * 
     * @return \Illuminate\Http\RedirectResponse
     */
    public function channel(Request $request, $id)
    {
        $channel = Channel::query()->with('playlist')->findOrFail($id);
        $playlist = $channel->getEffectivePlaylist();

        $url = app(M3uProxyService::class)->getChannelUrl($playlist, $id, $request);

        return redirect($url);
    }

    /**
     * Get the proxied URL for an episode and redirect
     * 
     * @param  Request  $request
     * @param  int  $id
     * 
     * @return \Illuminate\Http\RedirectResponse
     */
    public function episode(Request $request, $id)
    {
        $episode = Episode::query()->with('playlist')->findOrFail($id);
        $playlist = $episode->playlist;

        $url = app(M3uProxyService::class)->getEpisodeUrl($playlist, $id);

        return redirect($url);
    }

    /**
     * Handle webhooks from m3u-proxy for real-time cache invalidation
     */
    public function handleWebhook(Request $request)
    {
        $eventType = $request->input('event_type');
        $streamId = $request->input('stream_id');
        $data = $request->input('data', []);

        Log::info('Received m3u-proxy webhook', [
            'event_type' => $eventType,
            'stream_id' => $streamId,
            'data' => $data
        ]);

        // Invalidate caches based on event type
        switch ($eventType) {
            case 'CLIENT_CONNECTED':
            case 'CLIENT_DISCONNECTED':
            case 'STREAM_STARTED':
            case 'STREAM_ENDED':
                $this->invalidateStreamCaches($data);
                break;
        }

        return response()->json(['status' => 'ok']);
    }

    protected function invalidateStreamCaches(array $data): void
    {
        // Invalidate playlist-specific cache if we have metadata
        if (isset($data['playlist_uuid'])) {
            M3uProxyService::invalidateMetadataCache('playlist_uuid', $data['playlist_uuid']);
        }

        // Invalidate channel-specific cache if we have channel metadata
        if (isset($data['type'], $data['id'])) {
            M3uProxyService::invalidateMetadataCache('type', $data['type']);
            // We might also want to invalidate specific channel caches?
        }

        Log::info('Cache invalidated for m3u-proxy event', $data);
    }

    /**
     * Example player endpoint for channel using m3u-proxy
     * 
     * @param  Request  $request
     * @param  int  $id
     * 
     * @return StreamedResponse
     */
    public function channelPlayer(Request $request, $id)
    {
        $channel = Channel::query()->with('playlist')->findOrFail($id);
        $playlist = $channel->getEffectivePlaylist();

        $url = app(M3uProxyService::class)->getChannelUrl($playlist, $id);
        $format = pathinfo($url, PATHINFO_EXTENSION);

        $title = $channel->name_custom ?? $channel->name ?? $channel->title ?? 'Channel ' . $id;

        return PlayerTranscodingService::streamTranscodedContent($request, $url, $format, $title, $playlist);
    }

    /**
     * Example player endpoint for episode using m3u-proxy
     * 
     * @param  Request  $request
     * @param  int  $id
     * 
     * @return StreamedResponse
     */
    public function episodePlayer(Request $request, $id)
    {
        $episode = Episode::query()->with('playlist')->findOrFail($id);
        $playlist = $episode->playlist;

        $url = app(M3uProxyService::class)->getEpisodeUrl($playlist, $id);
        $format = pathinfo($url, PATHINFO_EXTENSION);

        $title = $episode->title ?? 'Episode ' . $id;

        return PlayerTranscodingService::streamTranscodedContent($request, $url, $format, $title, $playlist);
    }
}
