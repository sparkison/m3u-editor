<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Episode;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class M3uProxyService
{
    protected string $apiBaseUrl;

    public function __construct()
    {
        $this->apiBaseUrl = rtrim(config('proxy.m3u_proxy_url'), '/');
    }

    /**
     * Request or build a channel stream URL from the external m3u-proxy server.
     *
     * @param  Playlist|CustomPlaylist|MergedPlaylist|PlaylistAlias  $playlist
     * @param  int  $id
     *
     * @throws Exception when base URL missing or API returns an error
     */
    public function getChannelUrl($playlist, $id): string
    {
        if (empty($this->apiBaseUrl)) {
            throw new Exception('M3U Proxy base URL is not configured');
        }

        $channel = Channel::find($id);
        if (empty($channel)) {
            throw new Exception('Channel not found');
        }

        $primaryUrl = PlaylistUrlService::getChannelUrl($channel, $playlist);
        if (empty($primaryUrl)) {
            throw new Exception('Channel primary URL is empty');
        }

        $userAgent = $playlist->user_agent;
        $failovers = $channel->failoverChannels()
            ->select(['channels.id', 'channels.url', 'channels.url_custom'])->get()
            ->map(fn($ch) => PlaylistUrlService::getChannelUrl($ch, $playlist))
            ->filter()
            ->values()
            ->toArray();

        // Create/fetch the stream from the m3u-proxy API
        $streamId = $this->createOrUpdateStream($primaryUrl, $failovers, $userAgent, [
            'id' => $id,
            'type' => 'channel',
        ]);

        // Get the format from the URL
        $format = pathinfo($primaryUrl, PATHINFO_EXTENSION);
        $format = $format === 'm3u8' ? 'hls' : $format;

        // Return the proxy URL using the stream ID
        return $this->buildProxyUrl($streamId, $format);
    }

    /**
     * Request or build an episode stream URL from the external m3u-proxy server.
     *
     * @param  Playlist|CustomPlaylist|MergedPlaylist|PlaylistAlias  $playlist
     * @param  int  $id
     *
     * @throws Exception when base URL missing or API returns an error
     */
    public function getEpisodeUrl($playlist, $id): string
    {
        if (empty($this->apiBaseUrl)) {
            throw new Exception('M3U Proxy base URL is not configured');
        }

        $episode = Episode::find($id);
        if (empty($episode)) {
            throw new Exception('Episode not found');
        }

        $url = PlaylistUrlService::getEpisodeUrl($episode, $playlist);
        if (empty($url)) {
            throw new Exception('Episode URL is empty');
        }

        $userAgent = $playlist->user_agent;

        // Episodes typically don't have failovers, but we'll support it if needed
        $failoverUrls = [];

        // Create/fetch the stream from the m3u-proxy API
        $streamId = $this->createOrUpdateStream($url, $failoverUrls, $userAgent, [
            'id' => $id,
            'type' => 'episode',
        ]);

        // Get the format from the URL
        $format = pathinfo($url, PATHINFO_EXTENSION);
        $format = $format === 'm3u8' ? 'hls' : $format;

        // Return the proxy URL using the stream ID
        return $this->buildProxyUrl($streamId, $format);
    }

    /**
     * Delete/stop a stream on the external proxy (used by the Filament UI).
     * Returns true on success.
     */
    public function stopStream(string $streamId): bool
    {
        if (empty($this->apiBaseUrl)) {
            Log::warning('M3U Proxy base URL not configured');

            return false;
        }

        try {
            $endpoint = $this->apiBaseUrl . '/streams/' . $streamId;
            $response = Http::timeout(10)->acceptJson()->delete($endpoint);

            if ($response->successful()) {
                Log::info("Stream {$streamId} stopped successfully");

                return true;
            }

            Log::warning("Failed to stop stream {$streamId}: " . $response->body());

            return false;
        } catch (Exception $e) {
            Log::error("Error stopping stream {$streamId}: " . $e->getMessage());

            return false;
        }
    }

    /**
     * Fetch active streams from external proxy server API.
     * Returns array or empty array on failure.
     */
    public function fetchActiveStreams(): array
    {
        if (empty($this->apiBaseUrl)) {
            return [];
        }

        try {
            $endpoint = $this->apiBaseUrl . '/streams';
            $response = Http::timeout(5)->acceptJson()->get($endpoint);
            if ($response->successful()) {
                return $response->json() ?: [];
            }
        } catch (Exception $e) {
            Log::warning('Failed to fetch active streams from m3u-proxy: ' . $e->getMessage());
        }

        return [];
    }

    /**
     * Fetch active clients from external proxy server API.
     * Returns array or empty array on failure.
     */
    public function fetchActiveClients(): array
    {
        if (empty($this->apiBaseUrl)) {
            return [];
        }

        try {
            $endpoint = $this->apiBaseUrl . '/clients';
            $response = Http::timeout(5)->acceptJson()->get($endpoint);
            if ($response->successful()) {
                return $response->json() ?: [];
            }
        } catch (Exception $e) {
            Log::warning('Failed to fetch active clients from m3u-proxy: ' . $e->getMessage());
        }

        return [];
    }

    /**
     * Create or update a stream on the m3u-proxy API.
     * Returns the stream ID.
     *
     * @param  string  $url  Primary stream URL
     * @param  array  $failoverUrls  Array of failover URLs
     * @param  string|null  $userAgent  Custom user agent
     * @param  array  $metadata  Additional metadata (e.g. ['id' => 123, 'type' => 'channel'])
     * @return string Stream ID
     *
     * @throws Exception when API request fails
     */
    protected function createOrUpdateStream(string $url, array $failoverUrls = [], ?string $userAgent = null, array $metadata = []): string
    {
        try {
            $endpoint = $this->apiBaseUrl . '/streams';

            $payload = [
                'url' => $url,
                'failover_urls' => $failoverUrls ?: null,
                'metadata' => $metadata,
            ];

            if (! empty($userAgent)) {
                $payload['user_agent'] = $userAgent;
            }

            $response = Http::timeout(10)
                ->acceptJson()
                ->post($endpoint, $payload);

            if ($response->successful()) {
                $data = $response->json();

                if (isset($data['stream_id'])) {
                    Log::info('m3u-proxy stream created/updated successfully', [
                        'stream_id' => $data['stream_id'],
                        'url' => $url,
                    ]);

                    return $data['stream_id'];
                }

                throw new Exception('Stream ID not found in API response');
            }

            throw new Exception('Failed to create stream: ' . $response->body());
        } catch (Exception $e) {
            Log::error('Error creating/updating stream on m3u-proxy', [
                'error' => $e->getMessage(),
                'url' => $url,
            ]);
            throw $e;
        }
    }

    /**
     * Build the proxy URL for a given stream ID.
     * Uses the configured proxy format (HLS or direct stream).
     *
     * @return string The full proxy URL
     */
    protected function buildProxyUrl(string $streamId, $format = 'hls'): string
    {
        $baseUrl = $this->apiBaseUrl;
        if ($format === 'hls') {
            // HLS format: /hls/{stream_id}/playlist.m3u8
            return $baseUrl . '/hls/' . $streamId . '/playlist.m3u8';
        }

        // Direct stream format: /stream/{stream_id}
        return $baseUrl . '/stream/' . $streamId;
    }
}
