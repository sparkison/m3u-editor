<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use App\Jobs\ProcessM3uImport;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use Illuminate\Http\Request;

class PlaylistController extends Controller
{
    /**
     *
     * Sync the selected Playlist.
     *
     * Use the `uuid` parameter to select the playlist to refresh.
     * You can find the playlist UUID by using the `User > Get your Playlists` endpoint.
     *
     * @param Request $request
     * @param string $uuid
     *
     * @return JsonResponse
     *
     * @unauthenticated
     * @response array{message: "Playlist is currently being synced..."}
     */
    public function refreshPlaylist(Request $request, string $uuid)
    {
        $request->validate([
            // If true, will force a refresh of the EPG, ignoring any scheduling. Default is true.
            'force' => 'boolean',
        ]);

        // Fetch the playlist
        $playlist = Playlist::where('uuid', $uuid)->first();
        if (!$playlist) {
            $playlist = MergedPlaylist::where('uuid', $uuid)->first();
        }
        if (!$playlist) {
            $playlist = CustomPlaylist::where('uuid', $uuid)->firstOrFail();
        }

        // Refresh the playlist
        dispatch(new ProcessM3uImport($playlist, $request->force ?? true));

        return response()->json([
            'message' => "Playlist \"{$playlist->name}\" is currently being synced...",
        ]);
    }
}
