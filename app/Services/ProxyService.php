<?php

namespace App\Services;

class ProxyService
{
    /**
     * Get the proxy URL for a channel
     *
     * @param string|int $id
     * @param string $format
     * @param string|null $playlist
     * @return string
     */
    public function getProxyUrlForChannel($id, $format = 'ts', $playlist = null)
    {
        $proxyUrlOverride = config('proxy.url_override');
        $proxyFormat = $format ?? config('proxy.proxy_format', 'ts');
        $id = rtrim(base64_encode($id), '=');
        $playlistId = $playlist ? rtrim(base64_encode($playlist), '=') : null;
        if ($proxyUrlOverride) {
            $proxyUrlOverride = rtrim($proxyUrlOverride, '/');
            if ($proxyFormat === 'hls') {
                $proxyUrlOverride = "$proxyUrlOverride/api/stream/$id";
                if ($playlistId) {
                    $proxyUrlOverride .= "/$playlistId";
                }
                return "$proxyUrlOverride/playlist.m3u8";
            } else {
                $proxyUrlOverride = "$proxyUrlOverride/stream/$id/$format";
                if ($playlistId) {
                    $proxyUrlOverride .= "/$playlistId";
                }
                return $proxyUrlOverride;
            }
        }
        
        return $proxyFormat === 'hls'
            ? route('stream.hls.playlist', [
                'encodedId' => $id,
                'encodedPlaylist' => $playlistId
            ])
            : route('stream', [
                'encodedId' => $id,
                'format' => $format,
                'encodedPlaylist' => $playlistId
            ]);
    }
}
