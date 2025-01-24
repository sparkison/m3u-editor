<?php

namespace App\Http\Controllers;

use App\Models\Playlist;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PlaylistGenerateController extends Controller
{
    public function __invoke(string $uuid)
    {
        // Fetch the playlist
        $playlist = Playlist::where('uuid', $uuid)->firstOrFail();

        // Generate a filename
        $filename = Str::slug($playlist->name) . '.m3u';

        // Get ll active channels
        return response()->stream(
            function () use ($playlist) {
                // Get all active channels
                $channels = $playlist->channels()
                    ->where('enabled', true)
                    ->orderBy('channel')
                    ->get();

                // Output the enabled channels
                echo "#EXTM3U\n";
                foreach ($channels as $channel) {
                    echo "#EXTINF:-1 tvg-chno=\"$channel->channel\" tvg-id=\"$channel->stream_id\" tvg-name=\"$channel->name\" tvg-logo=\"$channel->logo\" group-title=\"$channel->group\"," . $channel->title . "\n";
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
