<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class M3uProxyService
{
    protected string $apiBaseUrl;
    protected string $proxyUrlOverride;

    public function __construct()
    {
        $this->apiBaseUrl = rtrim(config('proxy.m3u_proxy_base_url'), '/');
        $this->proxyUrlOverride = rtrim(config('proxy.url_override'), '/');
    }

    /**
     * Request or build a stream URL from the external m3u-proxy server.
     *
     * @param string $type 'channel'|'episode'
     * @param string|int $id
     * @param string $format 'ts'|'hls' etc.
     * @param bool $preview if true, return preview/local route style
     * @return string
     *
     * @throws Exception when base URL missing or API returns an error
     */
    public function getStreamUrl(string $type, $id, string $format = 'ts', bool $preview = false): string
    {
        if (empty($this->apiBaseUrl)) {
            throw new Exception('M3U proxy base URL is not configured (M3U_PROXY_BASE_URL).');
        }

        $encoded = rtrim(base64_encode((string) $id), '=');

        // If preview, don't call external server - return the in-app route style (keeps preview behavior)
        if ($preview) {
            $baseUrl = $this->proxyUrlOverride ?: url();
            if ($type === 'episode') {
                return "{$baseUrl}/shared/stream/e/{$encoded}." . ($format === 'hls' ? 'm3u8' : $format);
            }
            return "{$baseUrl}/shared/stream/{$encoded}." . ($format === 'hls' ? 'm3u8' : $format);
        }

        // Try API first. The openapi for your external proxy may differ; adapt the endpoint/params as needed.
        try {
            $apiEndpoint = $this->apiBaseUrl . '/api/streams';
            $response = Http::timeout(5)->acceptJson()->get($apiEndpoint, [
                'type' => $type,
                'id' => $encoded,
                'format' => $format,
            ]);

            if ($response->successful()) {
                $payload = $response->json();

                // Support common response shapes: { url: '...' } or { data: { url: '...' } }
                $url = $payload['url'] ?? ($payload['data']['url'] ?? null);

                if (!empty($url)) {
                    return $url;
                }

                // If API returned data object with stream location
                if (!empty($payload['data']['stream_url'])) {
                    return $payload['data']['stream_url'];
                }
            }

            Log::debug('M3U proxy API call failed or returned unexpected payload', [
                'endpoint' => $apiEndpoint,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        } catch (Exception $e) {
            Log::warning('M3U proxy API request failed: ' . $e->getMessage());
            // fall-through to fallback URL building
        }

        // Fallback predictable URL format (mirrors existing url_override pattern)
        if ($type === 'episode') {
            return "{$this->apiBaseUrl}/shared/stream/e/{$encoded}." . ($format === 'hls' ? 'm3u8' : $format);
        }

        return "{$this->apiBaseUrl}/shared/stream/{$encoded}." . ($format === 'hls' ? 'm3u8' : $format);
    }

    /**
     * Delete/stop a stream on the external proxy (used by the Filament UI).
     * Returns true on success.
     */
    public function stopStream(string $streamId): bool
    {
        if (empty($this->apiBaseUrl)) {
            return false;
        }

        try {
            $endpoint = $this->apiBaseUrl . '/api/streams/' . urlencode($streamId);
            $response = Http::timeout(5)->delete($endpoint);
            return $response->successful();
        } catch (Exception $e) {
            Log::warning('Failed to stop stream on m3u-proxy: ' . $e->getMessage());
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
            $endpoint = $this->apiBaseUrl . '/api/streams';
            $response = Http::timeout(5)->acceptJson()->get($endpoint, ['status' => 'active']);
            if ($response->successful()) {
                return $response->json() ?: [];
            }
        } catch (Exception $e) {
            Log::warning('Failed to fetch active streams from m3u-proxy: ' . $e->getMessage());
        }

        return [];
    }
}
