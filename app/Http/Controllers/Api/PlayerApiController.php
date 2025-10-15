<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Services\PlayerTranscodingService;
use App\Services\PlaylistUrlService;
use App\Services\ProxyService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PlayerApiController extends Controller
{
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

        $url = PlaylistUrlService::getChannelUrl($channel, $playlist);
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

        $url = PlaylistUrlService::getEpisodeUrl($episode, $playlist);
        $format = pathinfo($url, PATHINFO_EXTENSION);

        $title = $episode->title ?? 'Episode ' . $id;

        return PlayerTranscodingService::streamTranscodedContent($request, $url, $format, $title, $playlist);
    }
}
