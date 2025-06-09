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
    private function findAuthenticatedPlaylistAndStreamModel(string $username, string $password, int $streamId, string $streamType): ?Model
    {
        $playlistModels = [Playlist::class, MergedPlaylist::class, CustomPlaylist::class];
        $authenticatedPlaylist = null;
        $streamModel = null;

        foreach ($playlistModels as $modelClass) {
            /** @var \Illuminate\Database\Eloquent\Builder $query */
            $query = $modelClass::query();
            $currentPlaylist = null;

            // Try to authenticate via PlaylistAuth
            $playlistViaAuth = (clone $query)->with('playlistAuths')
                ->whereHas('playlistAuths', function ($q) use ($username, $password) {
                    $q->where('username', $username)->where('password', $password)->where('enabled', true);
                })->first();

            if ($playlistViaAuth) {
                $currentPlaylist = $playlistViaAuth;
            }

            // If not authenticated via PlaylistAuth, try m3ue user if username is 'm3ue'
            if (!$currentPlaylist && $username === 'm3ue') {
                // Fetch playlists and check their user's password.
                // This part might be slow if there are many playlists.
                // Consider optimizing if User has a direct relation to its playlists.
                $playlistsForPotentialM3ue = (clone $query)->with('user')->get();
                foreach ($playlistsForPotentialM3ue as $p) {
                    if ($p->user && Hash::check($password, $p->user->password)) {
                        $currentPlaylist = $p;
                        break;
                    }
                }
            }

            if ($currentPlaylist) {
                $streamModel = $this->getValidatedStreamFromPlaylist($currentPlaylist, $streamId, $streamType);
                if ($streamModel) {
                    // $authenticatedPlaylist = $currentPlaylist; // Not strictly needed to return playlist itself anymore
                    break; // Found valid stream in an authenticated playlist
                }
            }
        }
        return $streamModel; // Returns Channel or Episode model, or null
    }

    /**
     * Validates if a stream (Channel or Episode) exists, is enabled, and belongs to the given authenticated playlist.
     * Returns the stream Model (Channel or Episode) if valid, otherwise null.
     */
    private function getValidatedStreamFromPlaylist(Model $playlist, int $streamId, string $streamType): ?Model
    {
        if ($streamType === 'live') {
            // Assuming all playlist types have a 'channels' relationship defined.
            return $playlist->channels()
                            ->where('channels.id', $streamId) // Qualify column name if pivot table involved
                            ->where('enabled', true)
                            ->first();
        } elseif ($streamType === 'vod') {
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
     * @tags Xtream API Streams
     * @summary Provides live stream access.
     * @description Authenticates the request based on Xtream credentials provided in the path.
     * If successful and the requested channel is valid and part of an authorized playlist,
     * this endpoint redirects to the actual internal stream URL.
     * The route for this endpoint is typically `/live/{username}/{password}/{streamId}.{format}`.
     *
     * @param \Illuminate\Http\Request $request The HTTP request
     * @param string $username User's Xtream API username (path parameter)
     * @param string $password User's Xtream API password (path parameter)
     * @param int $streamId The ID of the live stream (channel ID) (path parameter)
     * @param string $format The requested stream format (e.g., 'ts', 'm3u8') (path parameter)
     *
     * @response 302 scenario="Successful redirect to stream URL" description="Redirects to the internal live stream URL."
     * @response 403 scenario="Forbidden/Unauthorized" {"error": "Unauthorized or stream not found"}
     */
    public function handleLive(Request $request, string $username, string $password, int $streamId, string $format)
    {
        // Find the channel by ID
        if (strpos($streamId, '==') === false) {
            $streamId .= '=='; // right pad to ensure proper decoding
        }
        $streamId = base64_decode($streamId);
        $channel = $this->findAuthenticatedPlaylistAndStreamModel($username, $password, $streamId, 'live');

        if ($channel instanceof Channel) {
            $internalUrl = '';
            if (strtolower($format) === 'm3u8') {
                $internalUrl = route('stream.hls.playlist', ['encodedId' => $channel->id]); // Use $channel->id
            } else {
                $internalUrl = route('stream', ['encodedId' => $channel->id, 'format' => $format]); // Use $channel->id
            }
            return Redirect::to($internalUrl);
        }

        return response()->json(['error' => 'Unauthorized or stream not found'], 403);
    }

    /**
     * @tags Xtream API Streams
     * @summary Provides VOD stream access.
     * @description Authenticates the request based on Xtream credentials provided in the path.
     * If successful and the requested VOD episode is valid and part of an authorized playlist,
     * this endpoint redirects to the actual internal stream URL for the episode.
     * The route for this endpoint is typically `/series/{username}/{password}/{streamId}.{format}`.
     *
     * @param \Illuminate\Http\Request $request The HTTP request
     * @param string $username User's Xtream API username (path parameter)
     * @param string $password User's Xtream API password (path parameter)
     * @param int $streamId The ID of the VOD stream (episode ID) (path parameter)
     * @param string $format The requested stream format (e.g., 'mp4', 'mkv', 'm3u8') (path parameter)
     *
     * @response 302 scenario="Successful redirect to stream URL" description="Redirects to the internal VOD episode stream URL."
     * @response 403 scenario="Forbidden/Unauthorized" {"error": "Unauthorized or stream not found"}
     * @response 404 scenario="Not Found (e.g., VOD item not found before auth)" description="This can occur if the episode ID does not exist or its series is disabled, even before full authentication of the playlist completes. The error message might still be the generic 403 from the controller's main error path."
     */
    public function handleVod(Request $request, string $username, string $password, int $streamId, string $format)
    {
        // Find the episode by ID
        if (strpos($streamId, '==') === false) {
            $streamId .= '=='; // right pad to ensure proper decoding
        }
        $streamId = base64_decode($streamId);
        $episode = $this->findAuthenticatedPlaylistAndStreamModel($username, $password, $streamId, 'vod');

        if ($episode instanceof Episode) {
            $internalUrl = '';
            if (strtolower($format) === 'm3u8') {
                $internalUrl = route('stream.hls.episode', ['encodedId' => $episode->id]); // Use $episode->id
            } else {
                $internalUrl = route('stream.episode', ['encodedId' => $episode->id, 'format' => $format]); // Use $episode->id
            }
            return Redirect::to($internalUrl);
        }

        return response()->json(['error' => 'Unauthorized or stream not found'], 403);
    }
}
