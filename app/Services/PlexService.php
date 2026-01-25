<?php

namespace App\Services;

use App\Http\Controllers\MediaServerProxyController;
use App\Interfaces\MediaServer;
use App\Models\MediaServerIntegration;
use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PlexService implements MediaServer
{
    protected MediaServerIntegration $integration;

    protected string $baseUrl;

    protected string $apiKey;

    public function __construct(MediaServerIntegration $integration)
    {
        $this->integration = $integration;
        $this->baseUrl = $integration->base_url;
        $this->apiKey = $integration->api_key;
    }

    public static function make(MediaServerIntegration $integration): self
    {
        return new self($integration);
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->timeout(30)
            ->retry(2, 1000)
            ->withHeaders([
                'X-Plex-Token' => $this->apiKey,
                'Accept' => 'application/json',
            ]);
    }

    public function testConnection(): array
    {
        try {
            $response = $this->client()->get('/');

            if ($response->successful()) {
                $data = $response->json();
                $serverName = $data['MediaContainer']['friendlyName'] ?? 'Unknown';
                $version = $data['MediaContainer']['version'] ?? 'Unknown';

                return [
                    'success' => true,
                    'message' => 'Connection successful',
                    'server_name' => $serverName,
                    'version' => $version,
                ];
            }

            return [
                'success' => false,
                'message' => 'Server returned status: '.$response->status(),
            ];
        } catch (Exception $e) {
            Log::warning('PlexService: Connection test failed', [
                'integration_id' => $this->integration->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Connection failed: '.$e->getMessage(),
            ];
        }
    }

    public function fetchMovies(): Collection
    {
        $libraries = $this->fetchPlexLibraries('movie');
        $movies = collect();

        foreach ($libraries as $library) {
            try {
                $response = $this->client()->get("/library/sections/{$library['key']}/all");

                if ($response->successful()) {
                    $data = $response->json();
                    $items = collect($data['MediaContainer']['Metadata'] ?? [])
                        ->map(fn ($item) => $this->normalizeItem($item));
                    $movies = $movies->concat($items);
                }
            } catch (Exception $e) {
                Log::error('PlexService: Error fetching movies from library', [
                    'integration_id' => $this->integration->id,
                    'library_id' => $library['key'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $movies;
    }

    public function fetchSeries(): Collection
    {
        $libraries = $this->fetchPlexLibraries('show');
        $series = collect();

        foreach ($libraries as $library) {
            try {
                $response = $this->client()->get("/library/sections/{$library['key']}/all");

                if ($response->successful()) {
                    $data = $response->json();
                    $items = collect($data['MediaContainer']['Metadata'] ?? [])
                        ->map(fn ($item) => $this->normalizeItem($item));
                    $series = $series->concat($items);
                }
            } catch (Exception $e) {
                Log::error('PlexService: Error fetching series from library', [
                    'integration_id' => $this->integration->id,
                    'library_id' => $library['key'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $series;
    }

    /**
     * Fetch all Plex libraries of a specific type.
     *
     * @param  string  $type  'movie' or 'show'
     * @return Collection<int, array>
     */
    protected function fetchPlexLibraries(string $type): Collection
    {
        try {
            $response = $this->client()->get('/library/sections');

            if ($response->successful()) {
                $data = $response->json();
                $libraries = collect($data['MediaContainer']['Directory'] ?? []);

                return $libraries->where('type', $type);
            }

            return collect();
        } catch (Exception $e) {
            Log::error('PlexService: Error fetching libraries', [
                'integration_id' => $this->integration->id,
                'error' => $e->getMessage(),
            ]);

            return collect();
        }
    }

    /**
     * Normalize a Plex API item to a common format.
     */
    protected function normalizeItem(array $item): array
    {
        return [
            'Id' => $item['ratingKey'],
            'Name' => $item['title'],
            'Type' => ucfirst($item['type']),
            'ProductionYear' => $item['year'] ?? null,
            'Path' => $item['Media'][0]['Part'][0]['file'] ?? null,
            'CommunityRating' => $item['rating'] ?? null,
            'OfficialRating' => $item['contentRating'] ?? null,
            'Overview' => $item['summary'] ?? null,
            'RunTimeTicks' => isset($item['duration']) ? ($item['duration'] * 10000) : null,
            'Genres' => array_map(fn ($g) => $g['tag'], $item['Genre'] ?? []),
            'ImageTags' => [
                'Primary' => $item['thumb'] ?? null,
                'Backdrop' => $item['art'] ?? null,
            ],
            'MediaSources' => array_map(fn ($media) => [
                'Container' => $media['container'] ?? null,
            ], $item['Media'] ?? []),
        ];
    }

    public function fetchSeasons(string $seriesId): Collection
    {
        try {
            $response = $this->client()->get("/library/metadata/{$seriesId}/children");

            if ($response->successful()) {
                $data = $response->json();

                return collect($data['MediaContainer']['Metadata'] ?? [])
                    ->map(fn ($item) => $this->normalizeItem($item));
            }

            return collect();
        } catch (Exception $e) {
            Log::error('PlexService: Error fetching seasons', [
                'integration_id' => $this->integration->id,
                'series_id' => $seriesId,
                'error' => $e->getMessage(),
            ]);

            return collect();
        }
    }

    public function fetchEpisodes(string $seriesId, ?string $seasonId = null): Collection
    {
        try {
            $endpoint = $seasonId
                ? "/library/metadata/{$seasonId}/children"
                : "/library/metadata/{$seriesId}/allLeaves";

            $response = $this->client()->get($endpoint);

            if ($response->successful()) {
                $data = $response->json();

                return collect($data['MediaContainer']['Metadata'] ?? [])
                    ->map(fn ($item) => $this->normalizeItem($item));
            }

            return collect();
        } catch (Exception $e) {
            Log::error('PlexService: Error fetching episodes', [
                'integration_id' => $this->integration->id,
                'series_id' => $seriesId,
                'season_id' => $seasonId,
                'error' => $e->getMessage(),
            ]);

            return collect();
        }
    }

    public function getStreamUrl(string $itemId, string $container = 'ts'): string
    {
        // Use proxy URL to hide API key from external clients
        return MediaServerProxyController::generateStreamProxyUrl(
            $this->integration->id,
            $itemId,
            $container
        );
    }

    public function getDirectStreamUrl(Request $request, string $itemId, string $container = 'ts', array $transcodeOptions = []): string
    {
        try {
            $response = $this->client()->get("/library/metadata/{$itemId}");

            if ($response->successful()) {
                $data = $response->json();
                $metadata = $data['MediaContainer']['Metadata'][0] ?? null;

                if ($metadata && isset($metadata['Media'][0]['Part'][0]['key'])) {
                    $partKey = $metadata['Media'][0]['Part'][0]['key'];

                    // Base URL for the stream
                    $streamUrl = "{$this->baseUrl}{$partKey}";

                    // Start with the API key
                    $params = ['X-Plex-Token' => $this->apiKey];

                    // Handle seeking (StartTimeTicks from Emby/Jellyfin needs to be converted to seconds for Plex)
                    if ($request->has('StartTimeTicks')) {
                        $ticks = (int) $request->input('StartTimeTicks');
                        $seconds = $this->ticksToSeconds($ticks);
                        if ($seconds !== null) {
                            $params['offset'] = $seconds;
                        }
                    }

                    // Forward audio and subtitle stream indexes if provided
                    if ($request->has('AudioStreamIndex')) {
                        $params['audioStreamID'] = $request->input('AudioStreamIndex');
                    }
                    if ($request->has('SubtitleStreamIndex')) {
                        $params['subtitleStreamID'] = $request->input('SubtitleStreamIndex');
                    }

                    // If transcode options are provided use Plex's transcode endpoint
                    if (! empty($transcodeOptions)) {
                        $videoBitrate = $transcodeOptions['video_bitrate'] ?? null;
                        $audioBitrate = $transcodeOptions['audio_bitrate'] ?? null;
                        $maxWidth = $transcodeOptions['max_width'] ?? null;
                        $maxHeight = $transcodeOptions['max_height'] ?? null;
                        $resolution = $maxWidth && $maxHeight
                            ? "{$maxWidth}x{$maxHeight}"
                            : null;

                        $transcodeParams = array_filter([
                            'url' => $streamUrl,
                            'X-Plex-Token' => $this->apiKey,
                            'videoBitrate' => $videoBitrate,
                            'audioBitrate' => $audioBitrate,
                            'videoResolution' => $resolution,
                        ]);

                        // Preferred flow: ask Plex's universal decision endpoint for the correct
                        // start URL (it will provide session, protocol, and other required params).
                        try {
                            $decisionEndpoint = $this->baseUrl.'/video/:/transcode/universal/decision';
                            $decisionParams = [
                                'path' => "/library/metadata/{$itemId}",
                                'mediaIndex' => 0,
                                'partIndex' => 0,
                                'protocol' => 'hls',
                                'directPlay' => 0,
                                'directStream' => 1,
                                'fastSeek' => 1,
                                'location' => 'lan',
                                'hasMDE' => 1,
                            ];

                            Log::debug('Calling Plex decision endpoint', [
                                'endpoint' => $decisionEndpoint,
                                'params' => $decisionParams,
                                'headers' => ['X-Plex-Product' => 'm3u-proxy', 'X-Plex-Client-Identifier' => 'm3u-proxy'],
                            ]);

                            $decisionResp = Http::timeout(15)
                                ->withHeaders([
                                    'X-Plex-Token' => $this->apiKey,
                                    'X-Plex-Product' => 'Plex Web',
                                    'X-Plex-Client-Identifier' => 'm3u-proxy',
                                    'X-Plex-Platform' => 'Chrome',
                                    'X-Plex-Device' => 'OSX',
                                    'Accept-Language' => 'en',
                                ])
                                ->withoutRedirecting()
                                ->get($decisionEndpoint, $decisionParams);

                            // Expect a redirect (Location header) pointing to the actual start.* URL
                            if (in_array($decisionResp->status(), [301, 302, 303, 307, 308], true)) {
                                $loc = $decisionResp->header('Location');
                                if ($loc) {
                                    // Make absolute if necessary
                                    if (str_starts_with($loc, '/')) {
                                        $startUrl = rtrim($this->baseUrl, '/').$loc;
                                    } else {
                                        $startUrl = $loc;
                                    }

                                    Log::info('Plex decision returned start URL', [
                                        'item_id' => $itemId,
                                        'start_url' => $startUrl,
                                    ]);

                                    return $startUrl;
                                }
                            }

                            // If decision returned XML but no redirect, try calling start endpoints directly
                            if ($decisionResp->successful() && ! empty($decisionResp->body())) {
                                $startEndpoints = [
                                    $this->baseUrl.'/video/:/transcode/universal/start.mpd',
                                    $this->baseUrl.'/video/:/transcode/universal/start.m3u8',
                                ];

                                $startParamsBase = array_merge($decisionParams, [
                                    'hasMDE' => 1,
                                    'location' => 'lan',
                                    'fastSeek' => 1,
                                    // Ensure token is present in start URL query string so FFmpeg (no headers) can access it
                                    'X-Plex-Token' => $this->apiKey,
                                    'X-Plex-Client-Identifier' => 'm3u-proxy',
                                ]);

                                $sessionId = bin2hex(random_bytes(8));

                                foreach ($startEndpoints as $startEndpoint) {
                                    try {
                                        // Copy base params and adjust per-endpoint needs
                                        $endpointParams = $startParamsBase;

                                        // Use appropriate protocol for the chosen start endpoint
                                        if (str_ends_with($startEndpoint, '.mpd')) {
                                            $endpointParams['protocol'] = 'dash';
                                            // Prefer DASH codecs for mpd
                                            $endpointParams['X-Plex-Client-Profile-Extra'] = 'append-transcode-target-codec(type=videoProfile&context=streaming&videoCodec=h264,hevc&audioCodec=aac&protocol=dash)';
                                            $accept = 'application/dash+xml';
                                        } else {
                                            $endpointParams['protocol'] = 'hls';
                                            $endpointParams['X-Plex-Client-Profile-Extra'] = 'append-transcode-target-codec(type=videoProfile&context=streaming&videoCodec=h264&audioCodec=aac&protocol=hls)';
                                            $accept = 'application/vnd.apple.mpegurl';
                                        }

                                        // Provide a session param expected by Plex
                                        $endpointParams['session'] = $sessionId;

                                        Log::debug('Attempting Plex start endpoint', [
                                            'endpoint' => $startEndpoint,
                                            'params' => $endpointParams,
                                        ]);

                                        $startResp = Http::timeout(15)
                                            ->withHeaders([
                                                'X-Plex-Token' => $this->apiKey,
                                                'X-Plex-Product' => 'Plex Web',
                                                'X-Plex-Client-Identifier' => 'm3u-proxy',
                                                'X-Plex-Platform' => 'Chrome',
                                                'X-Plex-Device' => 'OSX',
                                                'X-Plex-Playback-Session-Id' => $sessionId,
                                                'Accept-Language' => 'en',
                                                'Accept' => $accept,
                                            ])
                                            ->withoutRedirecting()
                                            ->get($startEndpoint, $endpointParams);

                                        if (in_array($startResp->status(), [301, 302, 303, 307, 308], true)) {
                                            $loc = $startResp->header('Location');
                                            if ($loc) {
                                                $startUrl = str_starts_with($loc, '/') ? rtrim($this->baseUrl, '/').$loc : $loc;

                                                // Ensure token present on returned URL for downstream FFmpeg access
                                                $parsed = parse_url($startUrl);
                                                parse_str($parsed['query'] ?? '', $qs);
                                                if (empty($qs['X-Plex-Token'])) {
                                                    $sep = strpos($startUrl, '?') === false ? '?' : '&';
                                                    $startUrl = $startUrl.$sep.'X-Plex-Token='.urlencode($this->apiKey);
                                                }

                                                Log::info('Plex start endpoint redirected to', ['start_url' => $startUrl]);

                                                return $startUrl;
                                            }
                                        }

                                        if ($startResp->successful() && ! empty($startResp->body())) {
                                            // Ensure token present in constructed URL
                                            if (empty($endpointParams['X-Plex-Token'])) {
                                                $endpointParams['X-Plex-Token'] = $this->apiKey;
                                            }

                                            Log::info('Plex start endpoint returned content; using start endpoint URL', ['endpoint' => $startEndpoint, 'params' => $endpointParams]);

                                            // Build final start URL including all endpoint params (path, session, protocol, token, etc.)
                                            $query = http_build_query($endpointParams);
                                            $startUrl = $startEndpoint.(strpos($startEndpoint, '?') === false ? '?'.$query : '&'.$query);

                                            return $startUrl;
                                        }

                                        Log::warning('Plex start endpoint did not return usable response', [
                                            'endpoint' => $startEndpoint,
                                            'status' => $startResp->status(),
                                            'body_snippet' => substr($startResp->body(), 0, 500),
                                        ]);
                                    } catch (\Exception $e) {
                                        Log::warning('Exception calling Plex start endpoint', [
                                            'endpoint' => $startEndpoint,
                                            'exception' => $e->getMessage(),
                                        ]);
                                    }
                                }
                            }

                            // Nothing usable returned
                            Log::warning('Plex decision endpoint did not return start URL', [
                                'endpoint' => $decisionEndpoint,
                                'status' => $decisionResp->status(),
                                'body_snippet' => substr($decisionResp->body(), 0, 500),
                            ]);

                            return '';
                        } catch (Exception $e) {
                            Log::error('Error calling Plex decision endpoint', [
                                'exception' => $e->getMessage(),
                                'item_id' => $itemId,
                            ]);

                            return '';
                        }
                    }

                    // Return the full URL with query parameters for direct streaming
                    return $streamUrl.'?'.http_build_query($params);
                }
            }

            Log::warning('PlexService: Could not retrieve part key for streaming', [
                'integration_id' => $this->integration->id,
                'item_id' => $itemId,
            ]);

            return '';
        } catch (Exception $e) {
            Log::error('PlexService: Error getting direct stream URL', [
                'integration_id' => $this->integration->id,
                'item_id' => $itemId,
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    public function getImageUrl(string $itemId, string $imageType = 'Primary'): string
    {
        // Use proxy URL to hide API key from external clients
        return MediaServerProxyController::generateImageProxyUrl(
            $this->integration->id,
            $itemId,
            $imageType
        );
    }

    public function getDirectImageUrl(string $itemId, string $imageType = 'Primary'): string
    {
        $thumb = $imageType === 'Primary' ? 'thumb' : 'art';

        return "{$this->baseUrl}/library/metadata/{$itemId}/{$thumb}?X-Plex-Token={$this->apiKey}";
    }

    public function extractGenres(array $item): array
    {
        $genres = $item['Genres'] ?? [];

        if (empty($genres)) {
            return ['Uncategorized'];
        }

        if ($this->integration->genre_handling === 'primary') {
            return [reset($genres)];
        }

        return $genres;
    }

    public function getContainerExtension(array $item): string
    {
        $mediaSources = $item['MediaSources'] ?? [];

        if (! empty($mediaSources)) {
            $container = $mediaSources[0]['Container'] ?? null;
            if ($container) {
                return strtolower($container);
            }
        }

        // Default fallback
        return 'ts';
    }

    public function ticksToSeconds(?int $ticks): ?int
    {
        if ($ticks === null) {
            return null;
        }

        // 10,000,000 ticks = 1 second
        return (int) ($ticks / 10000000);
    }
}
