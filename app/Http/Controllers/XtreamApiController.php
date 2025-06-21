<?php

namespace App\Http\Controllers;

use App\Enums\ChannelLogoType;
use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

class XtreamApiController extends Controller
{
    /**
     * Main Xtream API request handler.
     * 
     * This endpoint serves as the primary interface for Xtream API interactions.
     * It requires authentication via username and password query parameters.
     * The 'action' query parameter dictates the specific operation to perform and the structure of the response.
     * Common actions include 'panel' (default, retrieves player_api.php equivalent), 'get_live_streams', 'get_vod_streams', 
     * 'get_live_categories', and 'get_vod_categories'.
     * The detailed response structure for each action is documented in inline PHPDoc blocks within the method implementation.
     *
     * @param string $uuid The UUID of the playlist (required path parameter)
     * @param \Illuminate\Http\Request $request The HTTP request containing query parameters:
     *   - username (string, required): User's Xtream API username
     *   - password (string, required): User's Xtream API password  
     *   - action (string, optional): Defaults to 'panel'. Determines the API action (e.g., 'panel', 'get_live_streams', 'get_vod_streams', 'get_live_categories', 'get_vod_categories')
     *
     * @response 200 scenario="Successful response (structure varies by action)"
     * Example for 'panel' action:
     * @responseField user_info object User authentication and subscription details.
     * @responseField user_info.username string The username.
     * @responseField user_info.password string The password.
     * @responseField user_info.message string Optional message from server.
     * @responseField user_info.auth int Authentication status (1 for success).
     * @responseField user_info.status string Subscription status (e.g., "Active").
     * @responseField user_info.exp_date string Subscription expiry timestamp or null.
     * @responseField user_info.is_trial string "0" or "1".
     * @responseField user_info.active_cons int Number of active connections.
     * @responseField user_info.created_at string Account creation timestamp.
     * @responseField user_info.max_connections string Maximum allowed connections.
     * @responseField user_info.allowed_output_formats array Allowed output formats (e.g., ["m3u8", "ts"]).
     * @responseField server_info object Server details and features.
     * @responseField server_info.url string Server URL.
     * @responseField server_info.port string Server port.
     * @responseField server_info.https_port string Server HTTPS port.
     * @responseField server_info.server_protocol string Server protocol (http/https).
     * @responseField server_info.timezone string Server timezone.
     * @responseField server_info.server_software string Name of the server software.
     * @responseField server_info.timestamp_now string Current server timestamp.
     * @responseField server_info.time_now string Current server time (YYYY-MM-DD HH:MM:SS).
     *
     * @response 400 scenario="Bad Request" {"error": "Invalid action"}
     * @response 401 scenario="Unauthorized - Missing Credentials" {"error": "Unauthorized - Missing credentials"}
     * @response 401 scenario="Unauthorized - Invalid Credentials" {"error": "Unauthorized"}
     * @response 404 scenario="Not Found (e.g., playlist not found)" {"error": "Playlist not found"}
     * 
     * @unauthenticated
     */
    public function handle(Request $request, string $uuid)
    {
        $playlist = null;
        // $playlistModelType = null; // Not strictly needed here anymore

        try {
            $playlist = Playlist::with([
                'playlistAuths',
                'user',
                'channels' => fn($q) => $q->where('enabled', true)->with(['group', 'epgChannel']),
                'series' => fn($q) => $q->where('enabled', true)->with(['seasons.episodes', 'category'])
            ])->where('uuid', $uuid)->firstOrFail();
            // $playlistModelType = 'Playlist';
        } catch (ModelNotFoundException $e) {
            try {
                $playlist = MergedPlaylist::with([
                    'playlistAuths',
                    'user',
                    'channels' => fn($q) => $q->where('enabled', true)->with(['group', 'epgChannel'])
                ])->where('uuid', $uuid)->firstOrFail();
                // $playlistModelType = 'MergedPlaylist';
                if (method_exists($playlist, 'series')) {
                    $playlist->load(['series' => fn($q) => $q->where('enabled', true)->with(['seasons.episodes', 'category'])]);
                }
            } catch (ModelNotFoundException $e) {
                try {
                    $playlist = CustomPlaylist::with([
                        'playlistAuths',
                        'user',
                        'channels' => fn($q) => $q->where('enabled', true)->with(['group', 'epgChannel'])
                    ])->where('uuid', $uuid)->firstOrFail();
                    // $playlistModelType = 'CustomPlaylist';
                    if (method_exists($playlist, 'series')) {
                        $playlist->load(['series' => fn($q) => $q->where('enabled', true)->with(['seasons.episodes', 'category'])]);
                    }
                } catch (ModelNotFoundException $e) {
                    return response()->json(['error' => 'Playlist not found'], 404);
                }
            }
        }

        $username = $request->input('username');
        $password = $request->input('password');
        $authenticated = false;

        if (empty($username) || empty($password)) {
            return response()->json(['error' => 'Unauthorized - Missing credentials'], 401);
        }

        // Check for PlaylistAuth authentication
        $enabledAuth = $playlist->playlistAuths->where('enabled', true)->first();
        if ($enabledAuth && $enabledAuth->username === $username && $enabledAuth->password === $password) {
            $authenticated = true;
        }

        if (!$authenticated && $username === 'm3ue') {
            if ($playlist->user && Hash::check($password, $playlist->user->password)) {
                $authenticated = true;
            }
        }

        if (!$authenticated) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $action = $request->input('action', 'panel');

        if ($action === 'panel' || empty($request->input('action'))) {
            $now = Carbon::now();
            $userInfo = [
                'username' => $username,
                'password' => $password,
                'message' => '',
                'auth' => 1,
                'status' => 'Active',
                'exp_date' => (string)$now->copy()->addYears(10)->timestamp,
                'is_trial' => '0',
                'active_cons' => 1,
                'created_at' => (string)($playlist->user ? $playlist->user->created_at->timestamp : $now->timestamp),
                'max_connections' => (string)($playlist->streams ?? 1),
                'allowed_output_formats' => ['m3u8', 'ts'],
            ];

            $scheme = $request->getScheme();
            $host = $request->getHost();
            $currentPort = $request->getPort();
            $baseUrl = $scheme . '://' . $host;
            $httpsPort = ($scheme === 'https') ? (string)$currentPort : "443";

            $serverInfo = [
                'url' => $baseUrl,
                'port' => (string)$currentPort,
                'https_port' => $httpsPort,
                'rtmp_port' => null, // RTMP not available currently
                'server_protocol' => $scheme,
                'timezone' => Config::get('app.timezone', 'UTC'),
                'server_software' => config('app.name') . ' Xtream API',
                'timestamp_now' => (string)$now->timestamp,
                'time_now' => $now->toDateTimeString(),
            ];

            return response()->json([
                'user_info' => $userInfo,
                'server_info' => $serverInfo
            ]);
        }
        /**
         * Action: get_live_streams
         * Returns a JSON array of live stream objects for the authenticated playlist.
         * Only enabled channels are included.
         *
         * Response Structure:
         * [
         *   {
         *     "num": int, (Sequential number)
         *     "name": string, (Channel name)
         *     "stream_type": "live",
         *     "stream_id": int, (Channel ID)
         *     "stream_icon": string, (URL to channel icon)
         *     "epg_channel_id": string, (EPG channel ID)
         *     "added": string, (Timestamp of when channel was added)
         *     "category_id": string, (Category ID)
         *     "tv_archive": int, (0 or 1 if TV archive is enabled)
         *     "direct_source": string, (Direct stream URL, potentially empty if not applicable)
         *     "tv_archive_duration": int (Duration of TV archive in hours, e.g., 72)
         *   },
         *   ...
         * ]
         */
        else if ($action === 'get_live_streams') {
            $enabledChannels = $playlist->channels()
                ->where('enabled', true)
                ->where('is_vod', false)
                ->get();
            $liveStreams = [];
            if ($enabledChannels instanceof \Illuminate\Database\Eloquent\Collection) {
                foreach ($enabledChannels as $index => $channel) {
                    $streamIcon = url('/placeholder.png');
                    if ($channel->logo_type === ChannelLogoType::Epg && $channel->epgChannel && $channel->epgChannel->icon) {
                        $streamIcon = $channel->epgChannel->icon;
                    } elseif ($channel->logo_type === ChannelLogoType::Channel && $channel->logo) {
                        $streamIcon = filter_var($channel->logo, FILTER_VALIDATE_URL) ? $channel->logo : url($channel->logo);
                    }

                    $channelCategoryId = 'all';
                    if ($channel->group_id) {
                        $channelCategoryId = (string)$channel->group_id;
                    } elseif ($channel->group && $channel->group->id) {
                        $channelCategoryId = (string)$channel->group->id;
                    }
                    // It's better to ensure category_id exists in a predefined list of categories if strict adherence is needed.
                    // For now, defaulting to 'all' if not found or being more robust based on actual category data available.

                    $liveStreams[] = [
                        'num' => $channel->channel ?? null,
                        'name' => $channel->title_custom ?? $channel->title,
                        'stream_type' => 'live',
                        'stream_id' => base64_encode($channel->id),
                        'stream_icon' => $streamIcon,
                        'epg_channel_id' => $channel->epgChannel->epg_channel_id ?? $channel->stream_id_custom ?? $channel->stream_id ?? (string)$channel->id,
                        'added' => (string)$channel->created_at->timestamp,
                        'category_id' => $channelCategoryId, // Ensure this category_id is valid based on your categories logic
                        'tv_archive' => !empty($channel->catchup) ? 1 : 0, // Based on catchup field availability
                        'direct_source' => url("/live/{$username}/{$password}/" . base64_encode($channel->id) . ".ts"),
                        'tv_archive_duration' => !empty($channel->catchup) ? 24 : 0, // Default 24 hours if catchup available
                    ];
                }
            }
            return response()->json($liveStreams);
        }
        /**
         * Action: get_vod_streams
         * Returns a JSON array of VOD (Video on Demand) stream objects, representing series or movies.
         * Only enabled series/movies are included.
         *
         * Response Structure:
         * [
         *   {
         *     "num": int, (Sequential number)
         *     "name": string, (Series/Movie name)
         *     "series_id": int, (Series/Movie ID)
         *     "cover": string, (URL to cover image)
         *     "plot": string, (Plot summary)
         *     "cast": string, (Cast members)
         *     "director": string, (Director name)
         *     "genre": string, (Genre)
         *     "releaseDate": string, (Release date, YYYY-MM-DD)
         *     "last_modified": string, (Timestamp of last modification)
         *     "rating": string, (Rating, e.g., "8.5")
         *     "rating_5based": float, (Rating converted to a 5-based scale)
         *     "backdrop_path": array, (Array of backdrop image URLs, often empty)
         *     "youtube_trailer": string, (YouTube trailer ID or URL)
         *     "episode_run_time": string, (Episode run time, e.g., "25")
         *     "category_id": string (Category ID)
         *   },
         *   ...
         * ]
         */
        else if ($action === 'get_vod_streams') {
            $enabledSeriesCollection = $playlist->series()->where('enabled', true)->get();
            $enabledVodChannels = $playlist->channels()
                ->where('enabled', true)
                ->where('is_vod', true)
                ->get();
            $vodSeries = [];
            $now = Carbon::now(); // Ensure $now is available

            // First, add series/movies from the series table
            if ($enabledSeriesCollection instanceof \Illuminate\Database\Eloquent\Collection) {
                foreach ($enabledSeriesCollection as $index => $seriesItem) {
                    $seriesCategoryId = 'all'; // Default category_id
                    if ($seriesItem->category_id) {
                        $seriesCategoryId = (string)$seriesItem->category_id;
                    } elseif ($seriesItem->category && $seriesItem->category->id) {
                        $seriesCategoryId = (string)$seriesItem->category->id;
                    }
                    // Consider validating $seriesCategoryId against available categories if strictness is required.

                    $vodSeries[] = [
                        'num' => $index + 1,
                        'name' => $seriesItem->name,
                        'series_id' => (int)$seriesItem->id,
                        'cover' => $seriesItem->cover ? (filter_var($seriesItem->cover, FILTER_VALIDATE_URL) ? $seriesItem->cover : url($seriesItem->cover)) : url('/placeholder.png'),
                        'plot' => $seriesItem->plot ?? '',
                        'cast' => $seriesItem->cast ?? '',
                        'director' => $seriesItem->director ?? '',
                        'genre' => $seriesItem->genre ?? '',
                        'releaseDate' => $seriesItem->release_date ? Carbon::parse($seriesItem->release_date)->format('Y-m-d') : '',
                        'last_modified' => (string)($seriesItem->updated_at ? $seriesItem->updated_at->timestamp : $now->timestamp),
                        'rating' => (string)($seriesItem->rating ?? 0),
                        'rating_5based' => round((floatval($seriesItem->rating ?? 0)) / 2, 1),
                        'backdrop_path' => $seriesItem->backdrop_path ?? [], // Assuming backdrop_path is an array or can be defaulted to []
                        'youtube_trailer' => $seriesItem->youtube_trailer ?? '',
                        'episode_run_time' => (string)($seriesItem->episode_run_time ?? 0),
                        'category_id' => $seriesCategoryId,
                    ];
                }
            }

            // Then, add VOD channels (movies/content marked as VOD in channels table)
            if ($enabledVodChannels instanceof \Illuminate\Database\Eloquent\Collection) {
                $seriesCount = count($vodSeries);
                foreach ($enabledVodChannels as $index => $vodChannel) {
                    $channelCategoryId = 'all'; // Default category_id
                    if ($vodChannel->group_id) {
                        $channelCategoryId = (string)$vodChannel->group_id;
                    } elseif ($vodChannel->group && $vodChannel->group->id) {
                        $channelCategoryId = (string)$vodChannel->group->id;
                    }

                    $streamIcon = url('/placeholder.png');
                    if ($vodChannel->logo_type === ChannelLogoType::Epg && $vodChannel->epgChannel && $vodChannel->epgChannel->icon) {
                        $streamIcon = $vodChannel->epgChannel->icon;
                    } elseif ($vodChannel->logo_type === ChannelLogoType::Channel && $vodChannel->logo) {
                        $streamIcon = filter_var($vodChannel->logo, FILTER_VALIDATE_URL) ? $vodChannel->logo : url($vodChannel->logo);
                    }

                    $vodSeries[] = [
                        'num' => $seriesCount + $index + 1,
                        'name' => $vodChannel->title_custom ?? $vodChannel->title,
                        'series_id' => (int)$vodChannel->id,
                        'cover' => $streamIcon,
                        'plot' => '', // VOD channels don't typically have plot info
                        'cast' => '',
                        'director' => '',
                        'genre' => $vodChannel->group ?? '',
                        'releaseDate' => $vodChannel->created_at ? $vodChannel->created_at->format('Y-m-d') : '',
                        'last_modified' => (string)($vodChannel->updated_at ? $vodChannel->updated_at->timestamp : $now->timestamp),
                        'rating' => '0',
                        'rating_5based' => 0.0,
                        'backdrop_path' => [],
                        'youtube_trailer' => '',
                        'episode_run_time' => '0',
                        'category_id' => $channelCategoryId,
                    ];
                }
            }
            return response()->json($vodSeries);
        }
        /**
         * Action: get_live_categories
         * Returns a JSON array of live stream categories/groups.
         * Used to organize live channels into categories.
         *
         * Response Structure:
         * [
         *   {
         *     "category_id": string, (Group ID)
         *     "category_name": string, (Group name)
         *     "parent_id": int (Parent category ID, typically 0 for top-level)
         *   },
         *   ...
         * ]
         */
        else if ($action === 'get_live_categories') {
            $liveCategories = [];
            
            // Get all groups that have live channels (non-VOD channels)
            $groups = $playlist->groups()
                ->whereHas('channels', function($query) {
                    $query->where('enabled', true)
                          ->where('is_vod', false);
                })
                ->get();

            foreach ($groups as $group) {
                $liveCategories[] = [
                    'category_id' => (string)$group->id,
                    'category_name' => $group->name,
                    'parent_id' => 0, // Flat structure for now
                ];
            }

            // Add a default "All" category if no specific groups exist
            if (empty($liveCategories)) {
                $liveCategories[] = [
                    'category_id' => 'all',
                    'category_name' => 'All',
                    'parent_id' => 0,
                ];
            }

            return response()->json($liveCategories);
        }
        /**
         * Action: get_vod_categories
         * Returns a JSON array of VOD categories/groups.
         * Used to organize VOD content (series and VOD channels) into categories.
         *
         * Response Structure:
         * [
         *   {
         *     "category_id": string, (Category/Group ID)
         *     "category_name": string, (Category/Group name)
         *     "parent_id": int (Parent category ID, typically 0 for top-level)
         *   },
         *   ...
         * ]
         */
        else if ($action === 'get_vod_categories') {
            $vodCategories = [];
            
            // Get categories from series
            $seriesCategories = $playlist->series()
                ->where('enabled', true)
                ->with('category')
                ->get()
                ->pluck('category')
                ->filter()
                ->unique('id');

            foreach ($seriesCategories as $category) {
                $vodCategories[] = [
                    'category_id' => (string)$category->id,
                    'category_name' => $category->name,
                    'parent_id' => 0,
                ];
            }

            // Get groups from VOD channels
            $vodGroups = $playlist->groups()
                ->whereHas('channels', function($query) {
                    $query->where('enabled', true)
                          ->where('is_vod', true);
                })
                ->get();

            foreach ($vodGroups as $group) {
                // Check if this group is not already added from series categories
                $exists = collect($vodCategories)->contains(function($cat) use ($group) {
                    return $cat['category_id'] === (string)$group->id;
                });

                if (!$exists) {
                    $vodCategories[] = [
                        'category_id' => (string)$group->id,
                        'category_name' => $group->name,
                        'parent_id' => 0,
                    ];
                }
            }

            // Add a default "All" category if no specific categories exist
            if (empty($vodCategories)) {
                $vodCategories[] = [
                    'category_id' => 'all',
                    'category_name' => 'All',
                    'parent_id' => 0,
                ];
            }

            return response()->json($vodCategories);
        } else {
            return response()->json(['error' => "Action '{$action}' not implemented"]);
        }
    }
}
