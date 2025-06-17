<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Episode;
use App\Traits\TracksActiveStreams;
use Exception;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * MediaFlow-style proxy service implementation for m3u editor
 * 
 * This service replicates the functionality of MediaFlow proxy within
 * the existing Laravel framework, supporting HLS streams, direct streams,
 * and failover capabilities.
 */
class MediaFlowProxyService
{
    use TracksActiveStreams;

    public const SUPPORTED_VIDEO_FORMATS = ['mp4', 'mkv', 'avi', 'mov', 'webm', 'flv', 'wmv'];
    public const SUPPORTED_PLAYLIST_FORMATS = ['m3u8', 'm3u', 'm3u_plus'];
    public const DEFAULT_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0.0.0 Safari/537.36';
    
    /**
     * Proxy HLS manifest requests
     */
    public function proxyHlsManifest(string $destination, array $headers = [], array $params = []): StreamedResponse
    {
        $forcePlaylistProxy = $params['force_playlist_proxy'] ?? false;
        $keyUrl = $params['key_url'] ?? null;
        
        Log::channel('ffmpeg')->info("MediaFlow Proxy: Proxying HLS manifest for destination: {$destination}");
        
        return new StreamedResponse(function () use ($destination, $headers, $forcePlaylistProxy, $keyUrl) {
            try {
                $response = $this->makeHttpRequest($destination, 'GET', $headers);
                
                if ($response->successful()) {
                    $contentType = $response->header('Content-Type', 'application/vnd.apple.mpegurl');
                    
                    // Set headers for HLS manifest
                    header('Content-Type: ' . $contentType);
                    header('Cache-Control: no-cache, no-transform');
                    header('Connection: keep-alive');
                    
                    // Check if it's an M3U8 playlist
                    if (str_contains($contentType, 'mpegurl') || str_contains($destination, '.m3u8')) {
                        $content = $response->body();
                        $processedContent = $this->processM3u8Content($content, $destination, $forcePlaylistProxy, $keyUrl);
                        echo $processedContent;
                    } else {
                        // Stream the content directly
                        echo $response->body();
                    }
                } else {
                    http_response_code($response->status());
                    echo "Error: " . $response->status() . " - " . $response->body();
                }
            } catch (Exception $e) {
                Log::channel('ffmpeg')->error("MediaFlow Proxy HLS error: " . $e->getMessage());
                http_response_code(500);
                echo "Internal Server Error";
            }
        });
    }
    
    /**
     * Proxy generic stream requests
     */
    public function proxyStream(string $destination, array $headers = [], string $method = 'GET'): StreamedResponse
    {
        Log::channel('ffmpeg')->info("MediaFlow Proxy: Proxying stream for destination: {$destination}");
        
        return new StreamedResponse(function () use ($destination, $headers, $method) {
            try {
                $response = $this->makeHttpRequest($destination, $method, $headers, true);
                
                if ($response->successful()) {
                    // Forward response headers
                    foreach ($response->headers() as $name => $values) {
                        if (in_array(strtolower($name), [
                            'content-type', 'content-length', 'content-range', 
                            'accept-ranges', 'last-modified', 'etag', 'cache-control'
                        ])) {
                            foreach ((array) $values as $value) {
                                header("{$name}: {$value}");
                            }
                        }
                    }
                    
                    // Stream the content
                    echo $response->body();
                } else {
                    http_response_code($response->status());
                    echo "Error: " . $response->status();
                }
            } catch (Exception $e) {
                Log::channel('ffmpeg')->error("MediaFlow Proxy stream error: " . $e->getMessage());
                http_response_code(500);
                echo "Internal Server Error";
            }
        });
    }
    
    /**
     * Proxy stream with failover support for channels/episodes
     */
    public function proxyStreamWithFailover($modelType, $modelId, array $headers = [], array $params = []): StreamedResponse
    {
        $model = $this->getModel($modelType, $modelId);
        
        if (!$model) {
            abort(404, 'Model not found');
        }
        
        $playlist = $model->getEffectivePlaylist();
        
        if (!$playlist) {
            abort(404, 'Playlist not found');
        }
        
        // Increment active streams count
        $activeStreams = $this->incrementActiveStreams($playlist->id);
        
        // Check stream limits
        if ($this->wouldExceedStreamLimit($playlist->id, $playlist->available_streams, $activeStreams)) {
            $this->decrementActiveStreams($playlist->id);
            abort(503, 'Max streams reached for this playlist');
        }
        
        // Get streams including failovers
        $streams = $this->getStreamsWithFailovers($model);
        
        foreach ($streams as $stream) {
            $streamUrl = $this->getStreamUrl($stream);
            $streamTitle = $this->getStreamTitle($stream);
            
            if (!$streamUrl) {
                continue;
            }
            
            // Check if source is marked as bad
            $badSourceKey = "mfp:bad_source:{$stream->id}:{$playlist->id}";
            if (Redis::exists($badSourceKey)) {
                Log::channel('ffmpeg')->debug("MediaFlow Proxy: Skipping bad source {$stream->id}");
                continue;
            }
            
            try {
                Log::channel('ffmpeg')->info("MediaFlow Proxy: Attempting stream {$streamTitle} ({$stream->id})");
                
                return $this->createStreamResponse($streamUrl, $headers, $playlist->id, $stream);
                
            } catch (Exception $e) {
                Log::channel('ffmpeg')->error("MediaFlow Proxy: Stream failed for {$streamTitle}: " . $e->getMessage());
                
                // Mark source as bad
                Redis::setex($badSourceKey, 10, $e->getMessage());
                continue;
            }
        }
        
        // If we get here, all streams failed
        $this->decrementActiveStreams($playlist->id);
        abort(503, 'No available streams');
    }
    
    /**
     * Process M3U8 content to proxy URLs
     */
    private function processM3u8Content(string $content, string $baseUrl, bool $forcePlaylistProxy = false, ?string $keyUrl = null): string
    {
        $lines = explode("\n", $content);
        $processedLines = [];
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            if (empty($line) || str_starts_with($line, '#')) {
                // Handle key URL replacement
                if ($keyUrl && str_starts_with($line, '#EXT-X-KEY:')) {
                    $line = preg_replace('/URI="[^"]*"/', 'URI="' . $keyUrl . '"', $line);
                }
                $processedLines[] = $line;
                continue;
            }
            
            // This is a URL line
            if ($this->shouldProxyUrl($line, $baseUrl, $forcePlaylistProxy)) {
                $fullUrl = $this->resolveUrl($line, $baseUrl);
                $proxiedUrl = $this->generateProxyUrl($fullUrl);
                $processedLines[] = $proxiedUrl;
            } else {
                $processedLines[] = $line;
            }
        }
        
        return implode("\n", $processedLines);
    }
    
    /**
     * Determine if a URL should be proxied
     */
    private function shouldProxyUrl(string $url, string $baseUrl, bool $forcePlaylistProxy): bool
    {
        if ($forcePlaylistProxy) {
            return true;
        }
        
        $fullUrl = $this->resolveUrl($url, $baseUrl);
        $urlPath = parse_url($fullUrl, PHP_URL_PATH);
        
        // Always proxy playlist files
        foreach (self::SUPPORTED_PLAYLIST_FORMATS as $format) {
            if (str_ends_with($urlPath, ".{$format}")) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Resolve relative URL to absolute
     */
    private function resolveUrl(string $url, string $baseUrl): string
    {
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            return $url;
        }
        
        return rtrim($baseUrl, '/') . '/' . ltrim($url, '/');
    }
    
    /**
     * Generate proxy URL for MediaFlow-style endpoint
     */
    private function generateProxyUrl(string $url): string
    {
        $params = [
            'd' => $url
        ];
        
        // Determine endpoint based on URL
        if ($this->isPlaylistUrl($url)) {
            $endpoint = route('mediaflow.proxy.hls.manifest');
        } else {
            $endpoint = route('mediaflow.proxy.stream');
        }
        
        return $endpoint . '?' . http_build_query($params);
    }
    
    /**
     * Check if URL is a playlist
     */
    private function isPlaylistUrl(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH);
        
        foreach (self::SUPPORTED_PLAYLIST_FORMATS as $format) {
            if (str_ends_with($path, ".{$format}")) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Create streaming response with proper cleanup
     */
    private function createStreamResponse(string $url, array $headers, int $playlistId, $stream): StreamedResponse
    {
        $streamId = uniqid('mfp_');
        $startTime = time();
        
        // Store stream info in Redis for tracking
        Redis::setex("mfp:stream:{$streamId}:info", 3600, json_encode([
            'stream_id' => $streamId,
            'model_type' => $stream instanceof Channel ? 'channel' : 'episode',
            'model_id' => $stream->id,
            'playlist_id' => $playlistId,
            'url' => $url,
            'start_time' => $startTime,
            'client_ip' => request()->ip(),
        ]));
        
        return new StreamedResponse(function () use ($url, $headers, $playlistId, $stream, $streamId, $startTime) {
            $streamStarted = false;
            
            try {
                $response = $this->makeHttpRequest($url, 'GET', $headers, true);
                
                if ($response->successful()) {
                    $streamStarted = true;
                    
                    // Mark stream as active
                    Redis::setex("mfp:stream:{$streamId}:active", 3600, time());
                    
                    // Forward headers
                    foreach ($response->headers() as $name => $values) {
                        if (in_array(strtolower($name), [
                            'content-type', 'content-length', 'content-range', 
                            'accept-ranges', 'last-modified', 'etag'
                        ])) {
                            foreach ((array) $values as $value) {
                                header("{$name}: {$value}");
                            }
                        }
                    }
                    
                    // Log stream start
                    Log::channel('ffmpeg')->info("MediaFlow Proxy: Stream started - ID: {$streamId}, Model: {$stream->id}, Playlist: {$playlistId}");
                    
                    // Stream content
                    echo $response->body();
                } else {
                    throw new Exception("HTTP {$response->status()}: " . $response->body());
                }
            } catch (Exception $e) {
                if ($streamStarted) {
                    Log::channel('ffmpeg')->error("MediaFlow Proxy: Stream interrupted - ID: {$streamId}: " . $e->getMessage());
                } else {
                    throw $e;
                }
            } finally {
                if ($streamStarted) {
                    // Clean up Redis keys
                    Redis::del("mfp:stream:{$streamId}:info");
                    Redis::del("mfp:stream:{$streamId}:active");
                    
                    $this->decrementActiveStreams($playlistId);
                    
                    Log::channel('ffmpeg')->info("MediaFlow Proxy: Stream stopped - ID: {$streamId}, Duration: " . (time() - $startTime) . "s");
                }
            }
        });
    }
    
    /**
     * Make HTTP request with proper headers
     */
    private function makeHttpRequest(string $url, string $method = 'GET', array $headers = [], bool $stream = false): Response
    {
        $defaultHeaders = [
            'User-Agent' => self::DEFAULT_USER_AGENT,
        ];
        
        $allHeaders = array_merge($defaultHeaders, $headers);
        
        $httpClient = Http::withHeaders($allHeaders)
            ->timeout(30);
            
        if ($stream) {
            $httpClient->withOptions(['stream' => true]);
        }
        
        return $httpClient->send($method, $url);
    }
    
    /**
     * Get model by type and ID
     */
    private function getModel(string $type, int $id)
    {
        return match ($type) {
            'channel' => Channel::find($id),
            'episode' => Episode::find($id),
            default => null,
        };
    }
    
    /**
     * Get streams with failovers
     */
    private function getStreamsWithFailovers($model): \Illuminate\Support\Collection
    {
        if ($model instanceof Channel) {
            $streams = collect([$model]);
            
            if ($model->is_custom && !($model->url_custom || $model->url)) {
                // Custom channel with no URL - use only failovers
                return $model->failoverChannels;
            } else {
                return $streams->concat($model->failoverChannels);
            }
        }
        
        return collect([$model]);
    }
    
    /**
     * Get stream URL from model
     */
    private function getStreamUrl($model): ?string
    {
        if ($model instanceof Channel) {
            return $model->url_custom ?? $model->url;
        } elseif ($model instanceof Episode) {
            return $model->url;
        }
        
        return null;
    }
    
    /**
     * Get stream title from model
     */
    private function getStreamTitle($model): string
    {
        if ($model instanceof Channel) {
            return strip_tags($model->title_custom ?? $model->title);
        } elseif ($model instanceof Episode) {
            return strip_tags($model->title);
        }
        
        return 'Unknown Stream';
    }
}
