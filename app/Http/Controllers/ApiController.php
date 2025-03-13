<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessEpgImport;
use App\Jobs\ProcessM3uImport;
use App\Models\Epg;
use App\Models\Playlist;
use Illuminate\Http\Request;

class ApiController extends Controller
{
    /**
     * Get the authenticated User.
     *
     * @param \Illuminate\Http\Request $request
     * @return string[]
     * @response array{name: "admin"}
     */
    public function user(Request $request)
    {
        return $request->user()?->only('name');
    }

    /**
     * Sync the selected Playlist.
     *
     * Use the `playlist` parameter to select the playlist to refresh.
     * You can find the playlist ID by looking at the URL when viewing a playlist.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Models\Playlist $playlist
     * @param bool $force If true, will force a refresh of the Playlist, ignoring any scheduling.
     *
     * @return \Illuminate\Http\JsonResponse
     * @response array{message: "Response"}
     */
    public function refreshPlaylist(Request $request, Playlist $playlist, bool $force = true)
    {
        if ($request->user()->id !== $playlist->user_id) {
            return response()->json([
                'message' => 'Unauthorized',
            ])->setStatusCode(403);
        }

        // Refresh the playlist
        dispatch(new ProcessM3uImport($playlist, $force));

        return response()->json([
            'message' => "Playlist \"{$playlist->name}\" is currently being synced...",
        ]);
    }

    /**
     * Sync the selected EPG.
     *
     * Use the `epg` parameter to select the EPG to refresh.
     * You can find the EPG ID by looking at the URL when viewing an EPG.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Models\Playlist $epg
     * @param bool $force If true, will force a refresh of the EPG, ignoring any scheduling.
     *
     * @return \Illuminate\Http\JsonResponse
     * @response array{message: "Response"}
     */
    public function refreshEpg(Request $request, Epg $epg, bool $force = true)
    {
        if ($request->user()->id !== $epg->user_id) {
            return response()->json([
                'message' => 'Unauthorized',
            ])->setStatusCode(403);
        }

        // Refresh the EPG
        // Refresh the playlist
        dispatch(new ProcessEpgImport($epg, $force));

        return response()->json([
            'message' => "EPG \"{$epg->name}\" is currently being synced...",
        ]);
    }
}
