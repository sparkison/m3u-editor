<?php

namespace App\Http\Controllers;

use App\Models\Network;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

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
    public function playlist(Request $request, Network $network): RedirectResponse|Response
    {
        if (! $network->broadcast_enabled) {
            return response('Broadcast not enabled for this network', 404);
        }

        // Don't check broadcast_requested here - let the proxy determine availability.
        // This allows playback to work even if Laravel's state is momentarily out of sync.
        $proxyUrl = "{$this->proxyBaseUrl}/broadcast/{$network->uuid}/live.m3u8";

        return redirect()->to($proxyUrl);
    }

    /**
     * Serve an HLS segment file for a network.
     *
     * Proxies the request to the m3u-proxy service.
     */
    public function segment(Request $request, Network $network, string $segment): RedirectResponse|Response
    {
        if (! $network->broadcast_enabled) {
            return response('Broadcast not enabled for this network', 404);
        }

        $proxyUrl = "{$this->proxyBaseUrl}/broadcast/{$network->uuid}/segment/{$segment}.ts";

        return redirect()->to($proxyUrl);
    }
}
