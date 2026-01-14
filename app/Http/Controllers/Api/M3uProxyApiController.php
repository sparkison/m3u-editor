<?php

namespace App\Http\Controllers\Api;

use App\Facades\LogoFacade;
use App\Facades\PlaylistFacade;
use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\StreamProfile;
use App\Services\M3uProxyService;
use App\Settings\GeneralSettings;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

class M3uProxyApiController extends Controller
{
    /**
     * Get the proxied URL for a channel and redirect
     *
     * @param  int  $id
     * @param  string|null  $uuid  Optional playlist UUID for context
     * @return Response|RedirectResponse
     */
    public function channel(Request $request, $id, $uuid = null)
    {
        $channel = Channel::query()->with([
            'playlist',
            'customPlaylist',
        ])->findOrFail($id);

        // See if username is passed in request
        $username = $request->input('username', null);

        // If UUID provided, resolve that specific playlist (e.g., merged playlist)
        // Otherwise fall back to the channel's effective playlist
        if ($uuid) {
            $playlist = PlaylistFacade::resolvePlaylistByUuid($uuid);
            if (! $playlist) {
                return response()->json(['error' => 'Playlist not found'], 404);
            }
        } else {
            $playlist = $channel->getEffectivePlaylist();
        }

        // Load the stream profile relationships explicitly after getting the effective playlist
        // This ensures the relationship constraints are properly applied
        if ($playlist) {
            $playlist->load('streamProfile', 'vodStreamProfile');
        }

        // Get stream profile from playlist if set
        $profile = null;
        if ($channel->is_vod) {
            // For VOD channels, use the VOD stream profile if set
            $profile = $playlist->vodStreamProfile;
        } else {
            // Get stream profile from playlist if set
            $profile = $playlist->streamProfile;
        }

        $url = app(M3uProxyService::class)
            ->getChannelUrl(
                $playlist,
                $channel,
                $request,
                $profile
            );

        return redirect($url);
    }

    /**
     * Get the proxied URL for an episode and redirect
     *
     * @param  int  $id
     * @param  string|null  $uuid  Optional playlist UUID for context
     * @return Response|RedirectResponse
     */
    public function episode(Request $request, $id, $uuid = null)
    {
        $episode = Episode::query()->with([
            'playlist',
        ])->findOrFail($id);

        // See if username is passed in request
        $username = $request->input('username', null);

        // If UUID provided, resolve that specific playlist (e.g., merged playlist)
        // Otherwise fall back to the episode's playlist
        if ($uuid) {
            $playlist = PlaylistFacade::resolvePlaylistByUuid($uuid);
            if (! $playlist) {
                return response()->json(['error' => 'Playlist not found'], 404);
            }
        } else {
            $playlist = $episode->playlist;
        }

        // Load the stream profile relationships explicitly after getting the playlist
        if ($playlist) {
            $playlist->load('streamProfile', 'vodStreamProfile');
        }

        // For Series, use the VOD stream profile if set
        $profile = $playlist->vodStreamProfile;

        $url = app(M3uProxyService::class)
            ->getEpisodeUrl(
                $playlist,
                $episode,
                $profile
            );

        return redirect($url);
    }

    /**
     * Example player endpoint for channel using m3u-proxy
     *
     * @param  int  $id
     * @param  string|null  $uuid
     * @return RedirectResponse
     */
    public function channelPlayer(Request $request, $id, $uuid = null)
    {
        $channel = Channel::query()->with([
            'playlist',
            'customPlaylist',
        ])->findOrFail($id);

        if ($uuid) {
            $playlist = PlaylistFacade::resolvePlaylistByUuid($uuid);
        } else {
            $playlist = $channel->getEffectivePlaylist();
        }

        // Load the stream profile relationships explicitly after getting the effective playlist
        if ($playlist) {
            $playlist->load('streamProfile', 'vodStreamProfile');
        }

        // Get stream profile from playlist if set
        $profile = null;
        if ($channel->is_vod) {
            // For VOD channels, use the VOD stream profile if set
            $profile = $playlist->vodStreamProfile;
        } else {
            // Get stream profile from playlist if set
            $profile = $playlist->streamProfile;
        }

        // If no profile set, use default profile for the player
        // Preview player should always try to transcode for better compatibility
        if (! $profile) {
            // Use default profile set for the player
            $settings = app(GeneralSettings::class);
            if ($channel->is_vod) {
                $profileId = $settings->default_vod_stream_profile_id ?? null;
            } else {
                $profileId = $settings->default_stream_profile_id ?? null;
            }
            $profile = $profileId ? StreamProfile::find($profileId) : null;
        }

        $url = app(M3uProxyService::class)
            ->getChannelUrl(
                $playlist,
                $channel,
                $request,
                $profile
            );

        return redirect($url);
    }

    /**
     * Example player endpoint for episode using m3u-proxy
     *
     * @param  int  $id
     * @param  string|null  $uuid
     * @return RedirectResponse
     */
    public function episodePlayer(Request $request, $id, $uuid = null)
    {
        $episode = Episode::query()->with([
            'playlist',
        ])->findOrFail($id);

        if ($uuid) {
            $playlist = PlaylistFacade::resolvePlaylistByUuid($uuid);
        } else {
            $playlist = $episode->playlist;
        }

        // Load the stream profile relationships explicitly after getting the playlist
        if ($playlist) {
            $playlist->load('streamProfile', 'vodStreamProfile');
        }

        // Get stream profile from playlist if set
        $profile = $playlist->vodStreamProfile;
        if (! $profile) {
            // Use default profile set for the player
            $settings = app(GeneralSettings::class);
            $profileId = $settings->default_vod_stream_profile_id ?? null;
            $profile = $profileId ? StreamProfile::find($profileId) : null;
        }

        $url = app(M3uProxyService::class)
            ->getEpisodeUrl(
                $playlist,
                $episode,
                $profile
            );

        return redirect($url);
    }

    /**
     * Validate failover URLs for smart failover handling.
     * This endpoint is called by m3u-proxy during failover to get a viable failover URL
     * based on playlist capacity.
     *
     * Request format:
     * {
     *   "current_url": "http://example.com/stream",
     *   "metadata": {
     *      "id": 123,
     *      "playlist_uuid": "abc-def-ghi",
     *   }
     * }
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function resolveFailoverUrl(Request $request)
    {
        try {
            $currentUrl = $request->input('current_url');
            $metadata = $request->input('metadata', []);
            $failoverCount = $request->input('current_failover_index', 0);
            $channelId = $metadata['id'] ?? null;
            $playlistUuid = $metadata['playlist_uuid'] ?? null;

            if (! ($channelId && $currentUrl)) {
                return response()->json([
                    'next_url' => null,
                    'error' => 'Missing channel_id or current_url',
                ], 400);
            }

            // Use the M3uProxyService to validate the failover URLs
            $result = app(M3uProxyService::class)
                ->resolveFailoverUrl(
                    $channelId,
                    $playlistUuid,
                    $currentUrl,
                    index: $failoverCount
                );

            return response()->json($result);
        } catch (Exception $e) {
            Log::error('Error resolving failover: '.$e->getMessage(), $request->all());

            return response()->json([
                'next_url' => null,
                'error' => 'Validation failed: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handle webhooks from m3u-proxy for real-time cache invalidation
     */
    public function handleWebhook(Request $request)
    {
        $eventType = $request->input('event_type');
        $streamId = $request->input('stream_id');
        $data = $request->input('data', []);

        Log::info('Received m3u-proxy webhook', [
            'event_type' => $eventType,
            'stream_id' => $streamId,
            'data' => $data,
        ]);

        // Invalidate caches based on event type
        switch ($eventType) {
            case 'client_connected':
            case 'client_disconnected':
            case 'stream_started':
            case 'stream_stopped':
                $this->invalidateStreamCaches($data);
                break;
        }

        return response()->json(['status' => 'ok']);
    }

    protected function invalidateStreamCaches(array $data): void
    {
        // Invalidate playlist-specific cache if we have metadata
        if (isset($data['playlist_uuid'])) {
            M3uProxyService::invalidateMetadataCache('playlist_uuid', $data['playlist_uuid']);
        }

        // Invalidate channel-specific cache if we have channel metadata
        if (isset($data['type'], $data['id'])) {
            M3uProxyService::invalidateMetadataCache('type', $data['type']);
            // We might also want to invalidate specific channel caches?
        }

        Log::info('Cache invalidated for m3u-proxy event', $data);
    }

    /**
     * Get active streams data
     *
     * Returns the same structure as M3uProxyStreamMonitor page
     */
    public function activeStreams(Request $request, M3uProxyService $apiService)
    {
        $apiStreams = $apiService->fetchActiveStreams();
        $apiClients = $apiService->fetchActiveClients();

        // Check for connection errors
        if (! $apiStreams['success']) {
            return response()->json([
                'success' => false,
                'error' => $apiStreams['error'] ?? 'Unknown error connecting to m3u-proxy',
            ], 500);
        }

        if (! $apiClients['success']) {
            return response()->json([
                'success' => false,
                'error' => $apiClients['error'] ?? 'Unknown error connecting to m3u-proxy',
            ], 500);
        }

        if (empty($apiStreams['streams'])) {
            return response()->json([
                'success' => true,
                'streams' => [],
                'globalStats' => [
                    'total_streams' => 0,
                    'active_streams' => 0,
                    'total_clients' => 0,
                    'total_bandwidth_kbps' => 0,
                    'avg_clients_per_stream' => '0.00',
                ],
                'systemStats' => [],
            ]);
        }

        // Group clients by stream_id for easier lookup
        $clientsByStream = collect($apiClients['clients'] ?? [])
            ->groupBy('stream_id')
            ->toArray();

        $streams = [];
        foreach ($apiStreams['streams'] as $stream) {
            $streamId = $stream['stream_id'];
            $streamClients = $clientsByStream[$streamId] ?? [];

            // Get model information if metadata exists
            $model = [];
            if (isset($stream['metadata']['type']) && isset($stream['metadata']['id'])) {
                $modelType = $stream['metadata']['type'];
                $modelId = $stream['metadata']['id'];
                $title = null;
                $logo = null;

                if ($modelType === 'channel') {
                    $channel = Channel::find($modelId);
                    if ($channel) {
                        $title = $channel->name_custom ?? $channel->name ?? $channel->title;
                        $logo = LogoFacade::getChannelLogoUrl($channel);
                    }
                } elseif ($modelType === 'episode') {
                    $episode = Episode::find($modelId);
                    if ($episode) {
                        $title = $episode->title;
                        $logo = LogoFacade::getEpisodeLogoUrl($episode);
                    }
                }

                if ($title || $logo) {
                    $model = [
                        'title' => $title ?? 'N/A',
                        'logo' => $logo,
                    ];
                }
            }

            // Calculate uptime
            $startedAt = Carbon::parse($stream['created_at'], 'UTC');
            $uptime = $startedAt->diffForHumans(null, true);

            // Format bytes transferred
            $bytesTransferred = $this->formatBytes($stream['total_bytes_served']);

            // Calculate bandwidth (approximate based on bytes and time)
            $durationSeconds = $startedAt->diffInSeconds(now());
            $bandwidthKbps = $durationSeconds > 0
                ? round(($stream['total_bytes_served'] * 8) / $durationSeconds / 1000, 2)
                : 0;

            // Normalize clients
            $clients = array_map(function ($client) {
                $connectedAt = Carbon::parse($client['created_at'], 'UTC');
                $lastAccess = Carbon::parse($client['last_access'], 'UTC');

                // Client is considered active if:
                // 1. is_connected is true (from API), OR
                // 2. last_access was within the last 30 seconds (more lenient for active streaming)
                $isActive = ($client['is_connected'] ?? false) || $lastAccess->diffInSeconds(now()) < 30;

                return [
                    'ip' => $client['ip_address'],
                    'username' => $client['username'] ?? null,
                    'connected_at' => $connectedAt->format('Y-m-d H:i:s'),
                    'duration' => $connectedAt->diffForHumans(null, true),
                    'bytes_received' => $this->formatBytes($client['bytes_served']),
                    'bandwidth' => 'N/A', // Can calculate if needed
                    'is_active' => $isActive,
                ];
            }, $streamClients);

            $transcoding = $stream['metadata']['transcoding'] ?? false;
            $transcodingFormat = null;
            if ($transcoding) {
                $profile = StreamProfile::find($stream['metadata']['profile_id'] ?? null);
                if ($profile) {
                    $transcodingFormat = $profile->format === 'm3u8'
                        ? 'HLS'
                        : strtoupper($profile->format);
                }
            }

            $streams[] = [
                'stream_id' => $streamId,
                'source_url' => $this->truncateUrl($stream['original_url']),
                'current_url' => $stream['current_url'],
                'format' => strtoupper($stream['stream_type']),
                'status' => $stream['is_active'] && $stream['client_count'] > 0 ? 'active' : 'idle',
                'client_count' => $stream['client_count'],
                'bandwidth_kbps' => $bandwidthKbps,
                'bytes_transferred' => $bytesTransferred,
                'uptime' => $uptime,
                'started_at' => $startedAt->format('Y-m-d H:i:s'),
                'process_running' => $stream['is_active'] && $stream['client_count'] > 0,
                'model' => $model,
                'clients' => $clients,
                'has_failover' => $stream['has_failover'],
                'error_count' => $stream['error_count'],
                'segments_served' => $stream['total_segments_served'],
                'transcoding' => $transcoding,
                'transcoding_format' => $transcodingFormat,
                // Failover details
                'failover_urls' => $stream['failover_urls'] ?? [],
                'failover_resolver_url' => $stream['failover_resolver_url'] ?? null,
                'current_failover_index' => $stream['current_failover_index'] ?? 0,
                'failover_attempts' => $stream['failover_attempts'] ?? 0,
                'last_failover_time' => isset($stream['last_failover_time'])
                    ? Carbon::parse($stream['last_failover_time'], 'UTC')->format('Y-m-d H:i:s')
                    : null,
                'using_failover' => ($stream['current_failover_index'] ?? 0) > 0 || ($stream['failover_attempts'] ?? 0) > 0,
            ];
        }

        // Calculate global stats
        $totalClients = array_sum(array_map(fn ($s) => $s['client_count'] ?? 0, $streams));
        $totalBandwidth = array_sum(array_map(fn ($s) => $s['bandwidth_kbps'] ?? 0, $streams));
        $activeStreams = count(array_filter($streams, fn ($s) => $s['status'] === 'active'));

        $globalStats = [
            'total_streams' => count($streams),
            'active_streams' => $activeStreams,
            'total_clients' => $totalClients,
            'total_bandwidth_kbps' => round($totalBandwidth, 2),
            'avg_clients_per_stream' => count($streams) > 0
                ? number_format($totalClients / count($streams), 2)
                : '0.00',
        ];

        return response()->json([
            'success' => true,
            'streams' => $streams,
            'globalStats' => $globalStats,
            'systemStats' => [], // populate if external API provides system metrics
        ]);
    }

    /**
     * Truncate a URL for display
     */
    protected function truncateUrl(string $url, int $maxLength = 50): string
    {
        if (strlen($url) <= $maxLength) {
            return $url;
        }

        return substr($url, 0, $maxLength - 3).'...';
    }

    /**
     * Format bytes into human readable format
     */
    protected function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision).' '.$units[$i];
    }
}
