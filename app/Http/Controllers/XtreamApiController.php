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
     * 'get_live_categories', 'get_vod_categories', and 'get_vod_info'.
     * The detailed response structure for each action is documented in inline PHPDoc blocks within the method implementation.
     *
     * @param string $uuid The UUID of the playlist (required path parameter)
     * @param \Illuminate\Http\Request $request The HTTP request containing query parameters:
     *   - username (string, required): User's Xtream API username
     *   - password (string, required): User's Xtream API password  
     *   - action (string, optional): Defaults to 'panel'. Determines the API action (e.g., 'panel', 'get_live_streams', 'get_vod_streams', 'get_live_categories', 'get_vod_categories', 'get_vod_info')
     *   - vod_id (int, optional): Required if action is 'get_vod_info'. The ID of the VOD item
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
     * @response 400 scenario="Bad Request (e.g., missing vod_id for get_vod_info)" {"error": "Missing vod_id parameter"}
     * @response 401 scenario="Unauthorized - Missing Credentials" {"error": "Unauthorized - Missing credentials"}
     * @response 401 scenario="Unauthorized - Invalid Credentials" {"error": "Unauthorized"}
     * @response 404 scenario="Not Found (e.g., playlist or VOD item not found)" {"error": "Playlist not found"}
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
        }
        /**
         * Action: get_vod_info
         * Returns detailed information for a specific VOD item (series or movie), including its episodes if applicable.
         * Requires 'vod_id' (series_id) as a request parameter.
         *
         * Response Structure:
         * {
         *   "info": {
         *     "name": string, (Series/Movie name)
         *     "cover": string, (URL to cover image)
         *     "plot": string, (Plot summary)
         *     "cast": string, (Cast members)
         *     "director": string, (Director name)
         *     "genre": string, (Genre)
         *     "releaseDate": string, (Release date, YYYY-MM-DD)
         *     "last_modified": string, (Timestamp of last modification)
         *     "rating": string, (Rating, e.g., "8.5")
         *     "rating_5based": float, (Rating converted to a 5-based scale)
         *     "backdrop_path": array, (Array of backdrop image URLs)
         *     "youtube_trailer": string, (YouTube trailer ID or URL)
         *     "episode_run_time": string, (Episode run time)
         *     "category_id": string (Category ID)
         *   },
         *   "episodes": {
         *     "season_number": [  // Key is the season number
         *       {
         *         "id": string, (Episode ID)
         *         "episode_num": int, (Episode number within the season)
         *         "title": string, (Episode title)
         *         "container_extension": string, (e.g., "mp4", "mkv")
         *         "info": {
         *           "movie_image": string, (URL to episode thumbnail or series cover)
         *           "plot": string, (Episode plot summary)
         *           "duration_secs": int, (Duration in seconds)
         *           "duration": string, (Formatted duration HH:MM:SS)
         *           "video": array, (Video stream details - often empty)
         *           "audio": array, (Audio stream details - often empty)
         *           "bitrate": int, (Episode bitrate)
         *           "rating": string (Episode rating)
         *         },
         *         "added": string, (Timestamp of when episode was added)
         *         "season": int, (Season number this episode belongs to)
         *         "stream_id": int, (Episode ID, often same as "id" for Xtream Codes compatibility)
         *         "direct_source": string (Full URL to stream the episode)
         *       },
         *       ... // Other episodes in this season
         *     ],
         *     ... // Other seasons
         *   },
         *   "movie_data": { // Often duplicates info from "info" and adds some specific fields for player compatibility
         *     "stream_id": int, (Series/Movie ID)
         *     "name": string,
         *     "title": string,
         *     "year": string, (Release year)
         *     "episode_run_time": string,
         *     "plot": string,
         *     "cast": string,
         *     "director": string,
         *     "genre": string,
         *     "releaseDate": string,
         *     "last_modified": string,
         *     "rating": string,
         *     "rating_5based": float,
         *     "cover_big": string, (Often same as "info.cover")
         *     "youtube_trailer": string,
         *     "backdrop_path": array
         *   }
         * }
         */
        else if ($action === 'get_vod_info') {
            $vodId = $request->input('vod_id');
            if (!$vodId) {
                return response()->json(['error' => 'Missing vod_id parameter'], 400);
            }

            $seriesItem = null;
            $vodChannel = null;
            
            // First, try to find in series
            $enabledSeries = $playlist->series()->where('enabled', true)->get();
            if ($enabledSeries instanceof \Illuminate\Database\Eloquent\Collection && $enabledSeries->isNotEmpty()) {
                $seriesItem = $enabledSeries->firstWhere('id', $vodId);
            }
            
            // If not found in series, try to find in VOD channels
            if (!$seriesItem) {
                $enabledVodChannels = $playlist->channels()
                    ->where('enabled', true)
                    ->where('is_vod', true)
                    ->get();
                if ($enabledVodChannels instanceof \Illuminate\Database\Eloquent\Collection && $enabledVodChannels->isNotEmpty()) {
                    $vodChannel = $enabledVodChannels->firstWhere('id', $vodId);
                }
            }

            if (!$seriesItem && !$vodChannel) {
                return response()->json(['error' => 'VOD not found or not enabled'], 404);
            }

            $now = Carbon::now();
            
            // Handle series item
            if ($seriesItem) {
                $seriesInfo = [
                    'name' => $seriesItem->name,
                    'cover' => $seriesItem->cover ? (filter_var($seriesItem->cover, FILTER_VALIDATE_URL) ? $seriesItem->cover : url($seriesItem->cover)) : url('/placeholder.png'),
                    'plot' => $seriesItem->plot ?? '',
                    'cast' => $seriesItem->cast ?? '',
                    'director' => $seriesItem->director ?? '',
                    'genre' => $seriesItem->genre ?? '',
                    'releaseDate' => $seriesItem->release_date ? Carbon::parse($seriesItem->release_date)->format('Y-m-d') : '',
                    'last_modified' => (string)($seriesItem->updated_at ? $seriesItem->updated_at->timestamp : $now->timestamp),
                    'rating' => (string)($seriesItem->rating ?? 0),
                    'rating_5based' => round((floatval($seriesItem->rating ?? 0)) / 2, 1),
                    'backdrop_path' => $seriesItem->backdrop_path ?? [],
                    'youtube_trailer' => $seriesItem->youtube_trailer ?? '',
                    'episode_run_time' => (string)($seriesItem->episode_run_time ?? '0'), // Ensure string for consistency
                    'category_id' => (string)($seriesItem->category_id ?? ($seriesItem->category ? $seriesItem->category->id : 'all')),
                ];

                $episodesBySeason = [];
            if ($seriesItem->seasons && $seriesItem->seasons->isNotEmpty()) {
                foreach ($seriesItem->seasons as $season) {
                    $seasonNumber = $season->season_number;
                    $seasonEpisodes = [];
                    if ($season->episodes && $season->episodes->isNotEmpty()) {
                        // Order episodes by episode_num to ensure consistent ordering
                        $orderedEpisodes = $season->episodes->sortBy('episode_num');
                        foreach ($orderedEpisodes as $episode) {
                            // Construct stream URL - ensure username/password are available from the main handle method scope
                            $containerExtension = $episode->container_extension ?? 'mp4'; // Default or from model
                            // $streamUrlPath = "/series/{$uuid}/{$username}/{$password}/{$seriesItem->id}-{$episode->id}.{$containerExtension}";

                            $seasonEpisodes[] = [
                                'id' => (string)$episode->id,
                                'episode_num' => $episode->episode_num,
                                'title' => $episode->title ?? "Episode {$episode->episode_num}",
                                'container_extension' => $containerExtension,
                                'info' => [ // Basic info, can be expanded
                                    'movie_image' => $episode->thumbnail_url ?? ($seriesItem->cover ? (filter_var($seriesItem->cover, FILTER_VALIDATE_URL) ? $seriesItem->cover : url($seriesItem->cover)) : url('/placeholder.png')),
                                    'plot' => '', // Episodes don't have plot in this schema
                                    'duration_secs' => $episode->duration_seconds ?? 0,
                                    'duration' => gmdate("H:i:s", $episode->duration_seconds ?? 0),
                                    'video' => [], // Placeholder for video details if any
                                    'audio' => [], // Placeholder for audio details if any
                                    'bitrate' => $episode->bitrate ?? 0,
                                    'rating' => (string)($episode->rating ?? 0),
                                ],
                                'added' => (string)($episode->created_at ? $episode->created_at->timestamp : $now->timestamp),
                                'season' => $seasonNumber,
                                // The key for the stream URL is usually the series_id for older API, but episode ID is more specific
                                // Xtream Codes typically uses the episode ID as the stream ID in this context.
                                'stream_id' => base64_encode($episode->id),
                                'direct_source' => url("/series/{$username}/{$password}/" . base64_encode($episode->id) . ".{$containerExtension}")
                            ];
                        }
                    }
                    if (!empty($seasonEpisodes)) {
                        $episodesBySeason[$seasonNumber] = $seasonEpisodes;
                    }
                }
            }

            // If no seasons/episodes, or to handle series that are like single movies (no seasons)
            // This part might need adjustment based on how single VOD items (non-series) are structured
            if (empty($episodesBySeason)) { // If no episodes found, treat as a movie-like item
                $containerExtension = 'mp4'; // Default extension for series treated as movies
                // $streamUrlPath = "/series/{$uuid}/{$username}/{$password}/{$seriesItem->id}.{$containerExtension}";
                $episodesBySeason[1] = [ // Default to season 1 for movies
                    [
                        'id' => (string)$seriesItem->id, // Use series ID as episode ID for movie
                        'episode_num' => 1,
                        'title' => $seriesItem->name,
                        'container_extension' => $containerExtension,
                        'info' => [
                            'movie_image' => $seriesItem->cover ? (filter_var($seriesItem->cover, FILTER_VALIDATE_URL) ? $seriesItem->cover : url($seriesItem->cover)) : url('/placeholder.png'),
                            'plot' => $seriesItem->plot ?? '',
                            'duration_secs' => $seriesItem->duration_seconds ?? 0, // Assuming duration on series for movies
                            'duration' => gmdate("H:i:s", $seriesItem->duration_seconds ?? 0),
                            'video' => [],
                            'audio' => [],
                            'bitrate' => $seriesItem->bitrate ?? 0, // Assuming bitrate on series for movies
                            'rating' => (string)($seriesItem->rating ?? 0),
                        ],
                        'added' => (string)($seriesItem->created_at ? $seriesItem->created_at->timestamp : $now->timestamp),
                        'season' => 1,
                        'stream_id' => base64_encode($seriesItem->id),
                        'direct_source' => url("/series/{$username}/{$password}/" . base64_encode($seriesItem->id) . ".{$containerExtension}")
                    ]
                ];
            }

            return response()->json([
                'info' => $seriesInfo,
                'episodes' => $episodesBySeason,
                // The 'movie_data' structure is often used for VOD info in Xtream Codes for player compatibility.
                // Replicating a common structure:
                'movie_data' => [
                    'stream_id' => (int)$seriesItem->id,
                    'name' => $seriesItem->name,
                    'title' => $seriesItem->name, // Often duplicated
                    'year' => $seriesItem->release_date ? Carbon::parse($seriesItem->release_date)->format('Y') : '',
                    'episode_run_time' => (string)($seriesItem->episode_run_time ?? '0'),
                    'plot' => $seriesItem->plot ?? '',
                    'cast' => $seriesItem->cast ?? '',
                    'director' => $seriesItem->director ?? '',
                    'genre' => $seriesItem->genre ?? '',
                    'releaseDate' => $seriesItem->release_date ? Carbon::parse($seriesItem->release_date)->format('Y-m-d') : '',
                    'last_modified' => (string)($seriesItem->updated_at ? $seriesItem->updated_at->timestamp : $now->timestamp),
                    'rating' => (string)($seriesItem->rating ?? 0),
                    'rating_5based' => round((floatval($seriesItem->rating ?? 0)) / 2, 1),
                    'cover_big' => $seriesItem->cover ? (filter_var($seriesItem->cover, FILTER_VALIDATE_URL) ? $seriesItem->cover : url($seriesItem->cover)) : url('/placeholder.png'), // Often 'cover_big' is used
                    'youtube_trailer' => $seriesItem->youtube_trailer ?? '',
                    'backdrop_path' => $seriesItem->backdrop_path ?? [],
                    // For movies without explicit episodes, direct source might be here
                    // This example assumes episodes are always structured, even for movies (as season 1, episode 1)
                ]
            ]);
            } else {
                // Handle VOD channel
                $streamIcon = url('/placeholder.png');
                if ($vodChannel->logo_type === ChannelLogoType::Epg && $vodChannel->epgChannel && $vodChannel->epgChannel->icon) {
                    $streamIcon = $vodChannel->epgChannel->icon;
                } elseif ($vodChannel->logo_type === ChannelLogoType::Channel && $vodChannel->logo) {
                    $streamIcon = filter_var($vodChannel->logo, FILTER_VALIDATE_URL) ? $vodChannel->logo : url($vodChannel->logo);
                }

                $channelCategoryId = 'all';
                if ($vodChannel->group_id) {
                    $channelCategoryId = (string)$vodChannel->group_id;
                } elseif ($vodChannel->group && $vodChannel->group->id) {
                    $channelCategoryId = (string)$vodChannel->group->id;
                }

                $vodInfo = [
                    'name' => $vodChannel->title_custom ?? $vodChannel->title,
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

                // VOD channels are treated as single movies (season 1, episode 1)
                $containerExtension = 'ts'; // Default extension for VOD channels
                $episodesBySeason = [
                    1 => [
                        [
                            'id' => (string)$vodChannel->id,
                            'episode_num' => 1,
                            'title' => $vodChannel->title_custom ?? $vodChannel->title,
                            'container_extension' => $containerExtension,
                            'info' => [
                                'movie_image' => $streamIcon,
                                'plot' => '', // VOD channels don't have plot
                                'duration_secs' => 0, // VOD channels don't have duration info
                                'duration' => '00:00:00',
                                'video' => [],
                                'audio' => [],
                                'bitrate' => 0,
                                'rating' => '0',
                            ],
                            'added' => (string)($vodChannel->created_at ? $vodChannel->created_at->timestamp : $now->timestamp),
                            'season' => 1,
                            'stream_id' => base64_encode($vodChannel->id),
                            'direct_source' => url("/live/{$username}/{$password}/" . base64_encode($vodChannel->id) . ".{$containerExtension}")
                        ]
                    ]
                ];

                return response()->json([
                    'info' => $vodInfo,
                    'episodes' => $episodesBySeason,
                    'movie_data' => [
                        'stream_id' => (int)$vodChannel->id,
                        'name' => $vodChannel->title_custom ?? $vodChannel->title,
                        'title' => $vodChannel->title_custom ?? $vodChannel->title,
                        'year' => $vodChannel->created_at ? $vodChannel->created_at->format('Y') : '',
                        'episode_run_time' => '0',
                        'plot' => '',
                        'cast' => '',
                        'director' => '',
                        'genre' => $vodChannel->group ?? '',
                        'releaseDate' => $vodChannel->created_at ? $vodChannel->created_at->format('Y-m-d') : '',
                        'last_modified' => (string)($vodChannel->updated_at ? $vodChannel->updated_at->timestamp : $now->timestamp),
                        'rating' => '0',
                        'rating_5based' => 0.0,
                        'cover_big' => $streamIcon,
                        'youtube_trailer' => '',
                        'backdrop_path' => [],
                    ]
                ]);
            }
        } else {
            return response()->json(['error' => "Action '{$action}' not implemented"]);
        }
    }
}
