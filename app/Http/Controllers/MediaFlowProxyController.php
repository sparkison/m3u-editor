<?php

namespace App\Http\Controllers;

use App\Services\MediaFlowProxyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * MediaFlow-style proxy controller
 * 
 * Handles proxy requests in MediaFlow format within the m3u editor framework
 */
class MediaFlowProxyController extends Controller
{
    public function __construct(
        private MediaFlowProxyService $mediaFlowProxyService
    ) {}
    
    /**
     * Proxy HLS manifest endpoint
     * GET /mediaflow/proxy/hls/manifest.m3u8
     */
    public function proxyHlsManifest(Request $request): StreamedResponse
    {
        $destination = $request->get('d');
        
        if (!$destination) {
            abort(400, 'Destination parameter (d) is required');
        }
        
        // Extract headers from request (h_ prefixed parameters)
        $headers = $this->extractProxyHeaders($request);
        
        // Extract other parameters
        $params = [
            'force_playlist_proxy' => $request->boolean('force_playlist_proxy'),
            'key_url' => $request->get('key_url'),
        ];
        
        return $this->mediaFlowProxyService->proxyHlsManifest($destination, $headers, $params);
    }
    
    /**
     * Proxy generic stream endpoint
     * GET /mediaflow/proxy/stream
     */
    public function proxyStream(Request $request): StreamedResponse
    {
        $destination = $request->get('d');
        
        if (!$destination) {
            abort(400, 'Destination parameter (d) is required');
        }
        
        // Extract headers from request
        $headers = $this->extractProxyHeaders($request);
        
        return $this->mediaFlowProxyService->proxyStream($destination, $headers, $request->method());
    }
    
    /**
     * Proxy channel stream with failover support
     * GET /mediaflow/proxy/channel/{id}/stream
     */
    public function proxyChannelStream(Request $request, int $id): StreamedResponse
    {
        // Extract headers from request
        $headers = $this->extractProxyHeaders($request);
        
        // Extract parameters
        $params = $request->only(['force_playlist_proxy', 'key_url']);
        
        return $this->mediaFlowProxyService->proxyStreamWithFailover('channel', $id, $headers, $params);
    }
    
    /**
     * Proxy episode stream with failover support
     * GET /mediaflow/proxy/episode/{id}/stream
     */
    public function proxyEpisodeStream(Request $request, int $id): StreamedResponse
    {
        // Extract headers from request
        $headers = $this->extractProxyHeaders($request);
        
        // Extract parameters
        $params = $request->only(['force_playlist_proxy', 'key_url']);
        
        return $this->mediaFlowProxyService->proxyStreamWithFailover('episode', $id, $headers, $params);
    }
    
    /**
     * Get public IP address (compatible with MediaFlow proxy)
     * GET /mediaflow/proxy/ip
     */
    public function getPublicIp(Request $request)
    {
        try {
            // Try to get the public IP using a reliable service
            $response = file_get_contents('https://api.ipify.org?format=json');
            $data = json_decode($response, true);
            
            if (isset($data['ip'])) {
                return response()->json(['ip' => $data['ip']]);
            }
            
            // Fallback to request IP
            return response()->json(['ip' => $request->ip()]);
            
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error('MediaFlow Proxy: Failed to get public IP: ' . $e->getMessage());
            
            // Return the request IP as fallback
            return response()->json(['ip' => $request->ip()]);
        }
    }
    
    /**
     * Health check endpoint
     * GET /mediaflow/proxy/health
     */
    public function healthCheck()
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'M3U Editor MediaFlow Proxy',
            'timestamp' => now()->toISOString(),
        ]);
    }
    
    /**
     * Extract headers with h_ prefix from request
     */
    private function extractProxyHeaders(Request $request): array
    {
        $headers = [];
        
        foreach ($request->query() as $key => $value) {
            if (str_starts_with($key, 'h_')) {
                $headerName = substr($key, 2); // Remove 'h_' prefix
                $headers[$headerName] = $value;
            }
        }
        
        return $headers;
    }
}
