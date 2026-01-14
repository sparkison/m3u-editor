<?php

namespace App\Http\Controllers;

use App\Models\Playlist;
use App\Services\M3uProxyService;
use Illuminate\Http\JsonResponse;

class ProxyController extends Controller
{
    /**
     * Get proxy status information.
     *
     * Returns the current state of the m3u-proxy including:
     * - Whether proxy is enabled
     * - Proxy mode (embedded/external)
     * - Proxy URL
     * - Active streams count and details
     * - Streams grouped by playlist
     * - Proxy server info and health
     *
     * @return JsonResponse
     *
     * @response array{
     *     proxy_enabled: bool,
     *     mode: string,
     *     proxy_url: string|null,
     *     using_failover_resolver: bool,
     *     active_streams: int,
     *     streams_by_playlist: array<array{playlist_uuid: string, playlist_name: string, active_count: int}>,
     *     proxy_info: array|null,
     *     health: string
     * }
     */
    public function status(): JsonResponse
    {
        $proxyEnabled = config('proxy.external_proxy_enabled', false);
        $proxyService = new M3uProxyService();

        $mode = $proxyService->mode();
        $proxyUrl = config('proxy.m3u_proxy_public_url') ?: config('proxy.m3u_proxy_host');
        $usingResolver = $proxyService->usingResolver();

        // Fetch active streams
        $activeStreamsData = $proxyService->fetchActiveStreams();
        $streams = $activeStreamsData['streams'] ?? [];
        $totalActiveStreams = count($streams);

        // Group streams by playlist
        $streamsByPlaylist = [];
        $playlistUuids = [];
        foreach ($streams as $stream) {
            $playlistUuid = $stream['metadata']['playlist_uuid'] ?? null;
            if ($playlistUuid) {
                if (!isset($streamsByPlaylist[$playlistUuid])) {
                    $streamsByPlaylist[$playlistUuid] = [
                        'playlist_uuid' => $playlistUuid,
                        'playlist_name' => null,
                        'active_count' => 0,
                    ];
                    $playlistUuids[] = $playlistUuid;
                }
                $streamsByPlaylist[$playlistUuid]['active_count']++;
            }
        }

        // Fetch playlist names
        if (!empty($playlistUuids)) {
            $playlists = Playlist::whereIn('uuid', $playlistUuids)->pluck('name', 'uuid');
            foreach ($streamsByPlaylist as $uuid => &$data) {
                $data['playlist_name'] = $playlists[$uuid] ?? 'Unknown';
            }
        }

        // Fetch proxy server info
        $proxyInfo = null;
        $health = 'unknown';
        if ($proxyEnabled) {
            $infoResult = $proxyService->getProxyInfo();
            if ($infoResult['success']) {
                $proxyInfo = $infoResult['info'];
                $health = 'healthy';
            } else {
                $health = 'unhealthy';
            }
        } else {
            $health = 'disabled';
        }

        return response()->json([
            'proxy_enabled' => $proxyEnabled,
            'mode' => $mode,
            'proxy_url' => $proxyUrl,
            'using_failover_resolver' => $usingResolver,
            'active_streams' => $totalActiveStreams,
            'streams_by_playlist' => array_values($streamsByPlaylist),
            'proxy_info' => $proxyInfo,
            'health' => $health,
        ]);
    }
}
