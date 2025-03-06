<?php

namespace App\Http\Controllers;

use App\Enums\ChannelLogoType;
use App\Models\Playlist;
use App\Models\MergedPlaylist;
use App\Models\CustomPlaylist;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PlaylistGenerateController extends Controller
{
    public function __invoke(string $uuid)
    {
        // Fetch the playlist
        $playlist = Playlist::where('uuid', $uuid)->first();
        if (!$playlist) {
            $playlist = MergedPlaylist::where('uuid', $uuid)->first();
        }
        if (!$playlist) {
            $playlist = CustomPlaylist::where('uuid', $uuid)->firstOrFail();
        }

        // Generate a filename
        $filename = Str::slug($playlist->name) . '.m3u';

        // Get ll active channels
        return response()->stream(
            function () use ($playlist) {
                // Get all active channels
                $channels = $playlist->channels()
                    ->where('enabled', true)
                    ->with('epgChannel')
                    ->orderBy('channel')
                    ->get();

                // Output the enabled channels
                echo "#EXTM3U\n";
                foreach ($channels as $channel) {
                    // Get the title and name
                    $title = $channel->title_custom ?? $channel->title;
                    $name = $channel->name_custom ?? $channel->name;
                    $tvgId = $channel->stream_id_custom ?? $channel->stream_id;
                    $url = $channel->url_custom ?? $channel->url;
                    $epgData = $channel->epgChannel ?? null;

                    // Get the icon
                    $icon = '';
                    if ($channel->logo_type === ChannelLogoType::Epg && $epgData) {
                        $icon = $epgData->icon ?? '';
                    } elseif ($channel->logo_type === ChannelLogoType::Channel) {
                        $icon = $channel->logo ?? '';
                    }

                    // Output the channel
                    echo "#EXTINF:-1 tvg-chno=\"$channel->channel\" tvg-id=\"$tvgId\" tvg-name=\"$name\" tvg-logo=\"$icon\" group-title=\"$channel->group\"," . $title . "\n";
                    echo $url . "\n";
                }
            },
            200,
            [
                'Access-Control-Allow-Origin' => '*',
                'Content-Disposition' => "attachment; filename=$filename",
                'Content-Type' => 'application/vnd.apple.mpegurl'
            ]
        );
    }
}
