<?php

namespace App\Http\Controllers;

use App\Models\Playlist;
use Illuminate\Http\Request;

class PlaylistGenerateController extends Controller
{
    public function __invoke(Playlist $playlist)
    {
        // Get ll active channels
        return response()->stream(
            function () use ($playlist) {
                // Get all active channels
                $channels = $playlist->channels()
                    ->where('enabled', true)
                    ->get();

                // Output the enabled channels
                echo "#EXTM3U\n";
                foreach ($channels as $channel) {
                    $channel_number = $channel->channel || 0;
                    echo "#EXTINF:-1 tvg-chno=\"$channel_number\" tvg-id=\"$channel->name\" tvg-name=\"$channel->name\" tvg-logo=\"$channel->logo\" group-title=\"$channel->group\"," . $channel->name . "\n";
                    echo $channel->url . "\n";
                }
            },
            200,
            [
                'Access-Control-Allow-Origin' => '*',
                'Content-Type' => 'application/vnd.apple.mpegurl'
            ]
        );
    }
}
