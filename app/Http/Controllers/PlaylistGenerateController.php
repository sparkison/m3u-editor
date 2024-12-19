<?php

namespace App\Http\Controllers;

use App\Models\Playlist;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PlaylistGenerateController extends Controller
{
    public function __invoke(Playlist $playlist)
    {
        // Generate a filename
        $filename = Str::replace('.', '', Str::snake($playlist->name)) . '.m3u8';

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
                'Content-Disposition' => "attachment; filename=$filename",
                'Content-Type' => 'application/vnd.apple.mpegurl'
            ]
        );
    }
}
