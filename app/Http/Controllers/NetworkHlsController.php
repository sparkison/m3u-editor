<?php

namespace App\Http\Controllers;

use App\Models\Network;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NetworkHlsController extends Controller
{
    protected string $proxyBaseUrl;

    protected ?string $proxyToken;

    public function __construct()
    {
        $host = config('proxy.m3u_proxy_host', 'localhost');
        $port = config('proxy.m3u_proxy_port', 8085);
        $this->proxyBaseUrl = "http://{$host}:{$port}";
        $this->proxyToken = config('proxy.m3u_proxy_token');
    }

    /**
     * Serve the HLS playlist (live.m3u8) for a network.
     *
     * Proxies the request to the m3u-proxy service which manages
     * the actual FFmpeg processes and HLS segments.
     */
    public function playlist(Request $request, Network $network): Response
    {
        if (! $network->broadcast_enabled) {
            return response('Broadcast not enabled for this network', 404);
        }

        // Don't check broadcast_requested here - let the proxy determine availability.
        // This allows playback to work even if Laravel's state is momentarily out of sync.

        try {
            $proxyUrl = "{$this->proxyBaseUrl}/broadcast/{$network->uuid}/live.m3u8";

            $response = Http::timeout(10)->get($proxyUrl);

            if ($response->successful()) {
                $content = $response->body();

                // Rewrite segment URLs to point to our Laravel routes
                // The proxy returns relative URLs like "live000001.ts"
                // We need to make them point to our segment route: /network/{uuid}/{segment}.ts
                // The route parameter {segment} expects the name without .ts extension
                $networkUuid = $network->uuid;
                $content = preg_replace_callback(
                    '/^(live\d+)\.ts$/m',
                    function ($matches) use ($networkUuid) {
                        // $matches[1] is the segment name without .ts (e.g., "live000001")
                        return route('network.hls.segment', [
                            'network' => $networkUuid,
                            'segment' => $matches[1],
                        ]);
                    },
                    $content
                );

                return response($content, 200)
                    ->header('Content-Type', 'application/vnd.apple.mpegurl; charset=UTF-8')
                    ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
                    ->header('Pragma', 'no-cache')
                    ->header('Expires', '0')
                    ->header('Access-Control-Allow-Origin', '*');
            }

            if ($response->status() === 404) {
                return response('Broadcast not started or no segments available', 503)
                    ->header('Retry-After', '5');
            }

            Log::warning('Failed to fetch playlist from proxy', [
                'network_id' => $network->id,
                'status' => $response->status(),
            ]);

            return response('Broadcast not available', 503)
                ->header('Retry-After', '5');
        } catch (\Exception $e) {
            Log::error('Error fetching playlist from proxy', [
                'network_id' => $network->id,
                'error' => $e->getMessage(),
            ]);

            return response('Broadcast service unavailable', 503)
                ->header('Retry-After', '5');
        }
    }

    /**
     * Serve an HLS segment file for a network.
     *
     * Proxies the request to the m3u-proxy service.
     */
    public function segment(Request $request, Network $network, string $segment): Response
    {
        if (! $network->broadcast_enabled) {
            return response('Broadcast not enabled for this network', 404);
        }

        // Sanitize segment name
        $segment = basename($segment);
        if (! preg_match('/^live\d+$/', $segment)) {
            return response('Invalid segment name', 400);
        }

        try {
            $proxyUrl = "{$this->proxyBaseUrl}/broadcast/{$network->uuid}/segment/{$segment}.ts";

            $response = Http::timeout(30)->get($proxyUrl);

            if ($response->successful()) {
                return response($response->body(), 200)
                    ->header('Content-Type', 'video/MP2T')
                    ->header('Cache-Control', 'max-age=86400') // Segments are immutable
                    ->header('Access-Control-Allow-Origin', '*');
            }

            if ($response->status() === 404) {
                return response('Segment not found', 404);
            }

            return response('Segment unavailable', 503);
        } catch (\Exception $e) {
            Log::error('Error fetching segment from proxy', [
                'network_id' => $network->id,
                'segment' => $segment,
                'error' => $e->getMessage(),
            ]);

            return response('Broadcast service unavailable', 503);
        }
    }
}
