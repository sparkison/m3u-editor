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
    public function handle(Request $request, string $uuid)
    {
        $playlist = null;
        // $playlistModelType = null; // Not strictly needed here anymore

        try {
            $playlist = Playlist::with([
                'playlistAuth', 'user',
                'channels' => fn($q) => $q->where('enabled', true)->with(['group', 'epgChannel']),
                'series' => fn($q) => $q->where('enabled', true)->with(['seasons.episodes', 'category'])
            ])->findOrFail($uuid);
            // $playlistModelType = 'Playlist';
        } catch (ModelNotFoundException $e) {
            try {
                $playlist = MergedPlaylist::with([
                    'playlistAuth', 'user',
                    'channels' => fn($q) => $q->where('enabled', true)->with(['group', 'epgChannel'])
                ])->findOrFail($uuid);
                // $playlistModelType = 'MergedPlaylist';
                if (method_exists($playlist, 'series')) {
                    $playlist->load(['series' => fn($q) => $q->where('enabled', true)->with(['seasons.episodes', 'category'])]);
                }
            } catch (ModelNotFoundException $e) {
                try {
                    $playlist = CustomPlaylist::with([
                        'playlistAuth', 'user',
                        'channels' => fn($q) => $q->where('enabled', true)->with(['group', 'epgChannel'])
                    ])->findOrFail($uuid);
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

        if ($playlist->playlistAuth && $playlist->playlistAuth->is_enabled) {
            if ($playlist->playlistAuth->username === $username && $playlist->playlistAuth->password === $password) {
                $authenticated = true;
            }
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

            $responseCategories = [];
            $categoryMap = []; // Used to ensure categories for channels/series exist in the list

            $enabledChannels = $playlist->channels ?? new Collection();
            if ($enabledChannels->isNotEmpty()) {
                $liveGroups = $enabledChannels->pluck('group')->filter()->unique('id');
                foreach ($liveGroups as $group) {
                    if ($group && !isset($categoryMap[$group->id])) {
                        $responseCategories[] = [
                            'category_id' => (string)$group->id,
                            'category_name' => $group->name,
                            'parent_id' => 0,
                        ];
                        $categoryMap[(string)$group->id] = true;
                    }
                }
            }

            $enabledSeriesCollection = $playlist->series ?? new Collection();
            if ($enabledSeriesCollection->isNotEmpty()) {
                 $vodCategories = $enabledSeriesCollection->pluck('category')->filter()->unique('id');
                 foreach ($vodCategories as $category) {
                     if ($category && !isset($categoryMap[$category->id])) {
                         $responseCategories[] = [
                             'category_id' => (string)$category->id,
                             'category_name' => $category->name,
                             'parent_id' => 0,
                         ];
                         $categoryMap[(string)$category->id] = true;
                     }
                 }
            }

            if (empty($responseCategories)) {
                $responseCategories[] = [
                    'category_id' => 'all',
                    'category_name' => 'All',
                    'parent_id' => 0,
                ];
                if (!isset($categoryMap['all'])) $categoryMap['all'] = true;
            }

            $liveStreams = [];
            foreach ($enabledChannels as $index => $channel) {
                $streamIcon = URL::asset('/placeholder.png');
                if ($channel->logo_type === ChannelLogoType::Epg && $channel->epgChannel && $channel->epgChannel->icon) {
                    $streamIcon = $channel->epgChannel->icon;
                } elseif ($channel->logo_type === ChannelLogoType::Channel && $channel->logo) {
                    $streamIcon = $channel->logo;
                }

                $channelCategoryId = 'all';
                if ($channel->group_id) {
                    $channelCategoryId = (string)$channel->group_id;
                } elseif ($channel->group && $channel->group->id) {
                    $channelCategoryId = (string)$channel->group->id;
                }
                if (!isset($categoryMap[$channelCategoryId])) {
                    $channelCategoryId = 'all';
                }

                $liveStreams[] = [
                    'num' => $index + 1,
                    'name' => $channel->title_custom ?? $channel->title,
                    'stream_type' => 'live',
                    'stream_id' => (int)$channel->id,
                    'stream_icon' => $streamIcon,
                    'epg_channel_id' => $channel->epgChannel->epg_channel_id ?? $channel->stream_id_custom ?? $channel->stream_id ?? (string)$channel->id,
                    'added' => (string)$channel->created_at->timestamp,
                    'category_id' => $channelCategoryId,
                    'tv_archive' => 0,
                    'direct_source' => '',
                    'tv_archive_duration' => 0,
                ];
            }

            $vodSeries = [];
            foreach ($enabledSeriesCollection as $index => $seriesItem) {
                $seriesCategoryId = 'all';
                if ($seriesItem->category_id) {
                    $seriesCategoryId = (string)$seriesItem->category_id;
                } elseif ($seriesItem->category && $seriesItem->category->id) {
                    $seriesCategoryId = (string)$seriesItem->category->id;
                }
                 if (!isset($categoryMap[$seriesCategoryId])) {
                    $seriesCategoryId = 'all';
                }

                $vodSeries[] = [
                    'num' => $index + 1,
                    'name' => $seriesItem->name,
                    'series_id' => (int)$seriesItem->id,
                    // Basic fields as per simplified requirement for this subtask
                    'cover' => $seriesItem->cover_image ?? URL::asset('/placeholder.png'), // Add cover with placeholder
                    'plot' => $seriesItem->plot_summary ?? '', // Add plot
                    'cast' => $seriesItem->cast ?? '', // Add cast
                    'director' => $seriesItem->director ?? '', // Add director
                    'genre' => $seriesItem->genre ?? '', // Add genre
                    'releaseDate' => $seriesItem->release_date ? Carbon::parse($seriesItem->release_date)->format('Y-m-d') : '', // Format date
                    'last_modified' => (string)($seriesItem->updated_at ? $seriesItem->updated_at->timestamp : $now->timestamp),
                    'rating' => (string)($seriesItem->rating ?? 0), // Default rating
                    'rating_5based' => round(($seriesItem->rating ?? 0) / 2, 1), // Convert 10-based to 5-based
                    'backdrop_path' => [], // Empty for now
                    'youtube_trailer' => $seriesItem->youtube_trailer ?? '',
                    'episode_run_time' => (string)($seriesItem->episode_run_time ?? 0),
                    'category_id' => $seriesCategoryId,
                ];
            }

            $scheme = $request->getScheme();
            $host = $request->getHost();
            $currentPort = $request->getPort();
            $baseUrl = $scheme . '://' . $host;
            $httpsPort = ($scheme === 'https') ? (string)$currentPort : "443";

            $serverInfo = [
                'url' => $baseUrl,
                'port' => (string)$currentPort,
                'https_port' => $httpsPort,
                'rtmp_port' => "1935",
                'server_protocol' => $scheme,
                'timezone' => Config::get('app.timezone', 'UTC'),
                'server_software' => 'MediaFlow Xtream API',
                'timestamp_now' => (string)$now->timestamp,
                'time_now' => $now->toDateTimeString(),
            ];

            return response()->json([
                'user_info' => $userInfo,
                'server_info' => $serverInfo,
                'available_channels' => $liveStreams,
                'series' => $vodSeries, // Populate this
                'categories' => $responseCategories,
            ]);
        } else {
            return response()->json(['error' => "Action '{$action}' not implemented"]);
        }
    }
}
