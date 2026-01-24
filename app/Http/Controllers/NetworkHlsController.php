<?php

namespace App\Http\Controllers;

use App\Models\Network;
use App\Services\M3uProxyService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

class NetworkHlsController extends Controller
{
    protected string $proxyBaseUrl;

    protected ?string $proxyToken;

    protected M3uProxyService $proxyService;

    public function __construct()
    {
        // Initialize the M3uProxyService
        // We'll use this to communicate with the proxy for broadcast management
        $this->proxyService = new M3uProxyService;
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
        $proxyUrl = $this->proxyService->getProxyBroadcastHlsUrl($network);

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

        $proxyUrl = $this->proxyService->getProxyBroadcastSegmentUrl($network, $segment);

        return redirect()->to($proxyUrl);
    }
}
