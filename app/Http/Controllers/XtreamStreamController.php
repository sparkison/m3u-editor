<?php

namespace App\Http\Controllers;

use App\Models\Playlist;
use App\Models\MergedPlaylist;
use App\Models\CustomPlaylist;
use App\Models\User;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Series;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\Eloquent\Model;

class XtreamStreamController extends Controller
{
    /**
     * Authenticates a playlist based on credentials and retrieves the validated stream Model (Channel or Episode).
     */
    private function findAuthenticatedPlaylistAndStreamModel(string $uuid, string $username, string $password, int $streamId, string $streamType): array
    {
        $streamModel = null;
        $playlistModel = Playlist::where('uuid', $uuid)->first();
        if (!$playlistModel) {
            $playlistModel = MergedPlaylist::where('uuid', $uuid)->first();
        }
        if (!$playlistModel) {
            $playlistModel = CustomPlaylist::where('uuid', $uuid)->firstOrFail();
        }

        // Try to authenticate via PlaylistAuth
        $playlistViaAuth = $playlistModel->playlistAuths->where('enabled', true)->first();
        if ($playlistViaAuth) {
            if ($playlistViaAuth->username === $username && $playlistViaAuth->password === $password) {
                // Authenticated via PlaylistAuth
                $streamModel = $this->getValidatedStreamFromPlaylist($playlistModel, $streamId, $streamType);
            }
        } else {
            $user = $playlistModel->user;
            if ($user && $user->name === $username && Hash::check($password, $user->password)) {
                // Authenticated via User
                $streamModel = $this->getValidatedStreamFromPlaylist($playlistModel, $streamId, $streamType);
            }
        }
        return [$playlistModel, $streamModel]; // Returns Channel or Episode model, or null
    }

    /**
     * Validates if a stream (Channel or Episode) exists, is enabled, and belongs to the given authenticated playlist.
     * Returns the stream Model (Channel or Episode) if valid, otherwise null.
     */
    private function getValidatedStreamFromPlaylist(Model $playlist, int $streamId, string $streamType): ?Model
    {
        // Live and VOD streams are handled the same
        if ($streamType === 'live' || $streamType === 'vod') {
            // Assuming all playlist types have a 'channels' relationship defined.
            return $playlist->channels()
                ->where('channels.id', $streamId) // Qualify column name if pivot table involved
                ->where('enabled', true)
                ->first();
        } elseif ($streamType === 'episode') {
            $episode = Episode::with('season.series')->find($streamId);
            if (!$episode) {
                return null; // Episode or its hierarchy not found
            }
            $series = $episode->season()?->series ?? null;
            if (!$series) {
                return null; // Series not found
            }
            if (!$series->enabled) {
                return null; // Series is disabled
            }

            // Validate series membership in the playlist.
            // This assumes all playlist types (Playlist, MergedPlaylist, CustomPlaylist)
            // have a 'series' relationship defined that correctly links to App\Models\Series.
            $isMember = $playlist->series()
                ->where('series.id', $series->id) // Qualify column name
                ->exists();

            return $isMember ? $episode : null;
        }
        return null;
    }

    /**
     * Live stream requests.
     * 
     * @tags Xtream API Streams
     * @summary Provides live stream access.
     * @description Authenticates the request based on Xtream credentials provided in the path.
     * If successful and the requested channel is valid and part of an authorized playlist,
     * this endpoint redirects to the actual internal stream URL.
     * The route for this endpoint is typically `/live/{username}/{password}/{streamId}.{format}`.
     *
     * @param \Illuminate\Http\Request $request The HTTP request
     * @param string $uuid The UUID of the Xtream API (path parameter)
     * @param string $username User's Xtream API username (path parameter)
     * @param string $password User's Xtream API password (path parameter)
     * @param string $streamId The ID of the live stream (channel ID) (path parameter)
     * @param string $format The requested stream format (e.g., 'ts', 'm3u8') (path parameter)
     *
     * @response 302 scenario="Successful redirect to stream URL" description="Redirects to the internal live stream URL."
     * @response 403 scenario="Forbidden/Unauthorized" {"error": "Unauthorized or stream not found"}
     * 
     * @unauthenticated
     */
    /**
     * Live stream requests.
     */
    public function handleLive(Request $request, string $username, string $password, int $streamId, string $format = 'ts')
    {
        list($playlist, $channel) = $this->findAuthenticatedPlaylistAndStreamModel($username, $password, $streamId, 'live');

        if ($channel instanceof Channel) {
            if ($playlist->enable_proxy) {
                $url = rtrim(route('stream', [
                    'encodedId' => rtrim(base64_encode($streamId), '=')
                ]), '.');
            } else {
                return Redirect::to($channel->url_custom ?? $channel->url);
            }
        }

        return response()->json(['error' => 'Unauthorized or stream not found'], 403);
    }

    /**
     * VOD stream requests.
     */
    public function handleVod(Request $request, string $username, string $password, string $streamId, string $format = 'ts')
    {
        list($playlist, $channel) = $this->findAuthenticatedPlaylistAndStreamModel($username, $password, $streamId, 'vod');

        if ($channel instanceof Channel) {
            if ($playlist->enable_proxy) {
                $url = rtrim(route('stream', [
                    'encodedId' => rtrim(base64_encode($streamId), '=')
                ]), '.');
            } else {
                return Redirect::to($channel->url_custom ?? $channel->url);
            }
        }

        return response()->json(['error' => 'Unauthorized or stream not found'], 403);
    }

    /**
     * Series episode stream requests.
     *
     * @tags Xtream API Streams
     * @summary Provides series episode stream access.
     * @description Authenticates the request based on Xtream credentials provided in the path.
     * If successful and the requested episode is valid and part of an authorized playlist,
     * this endpoint redirects to the actual internal stream URL for the episode.
     * The route for this endpoint is typically `/xtream/{uuid}/series/{username}/{password}/{episodeId}.{format}`.
     *
     * @param \Illuminate\Http\Request $request The HTTP request
     * @param string $uuid The UUID of the Xtream API (path parameter)
     * @param string $username User's Xtream API username (path parameter)
     * @param string $password User's Xtream API password (path parameter)
     * @param int $streamId The ID of the episode (path parameter)
     * @param string $format The requested stream format (e.g., 'mp4', 'mkv') (path parameter)
     *
     * @response 302 scenario="Successful redirect to stream URL" description="Redirects to the internal episode stream URL."
     * @response 403 scenario="Forbidden/Unauthorized" {"error": "Unauthorized or stream not found"}
     *
     * @unauthenticated
     */
    public function handleSeries(Request $request, string $uuid, string $username, string $password, int $streamId, string $format = 'mp4')
    {
        // Find the episode by ID
        if (strpos($streamId, '==') === false) {
            $streamId .= '=='; // right pad to ensure proper decoding
        }
        $episodeId = base64_decode($streamId);
        list($playlist, $episode) = $this->findAuthenticatedPlaylistAndStreamModel($uuid, $username, $password, (int)$episodeId, 'episode');

        if ($episode instanceof Episode) {
            if ($playlist->enable_proxy) {
                return Redirect::to(route('stream.episode', ['encodedId' => $streamId, 'format' => $format]));
            } else {
                return Redirect::to($episode->url);
            }
        }

        return response()->json(['error' => 'Unauthorized or stream not found'], 403);
    }
}
