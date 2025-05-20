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
    public function getProxyUrlForChannel($id, $format = 'mp2t', $playlist = null)
    {
        $proxyUrlOverride = config('proxy.url_override');
        $proxyFormat = $format ?? config('proxy.proxy_format', 'mp2t');
        $id = rtrim(base64_encode($id), '=');
        if ($proxyUrlOverride) {
            $proxyUrlOverride = rtrim($proxyUrlOverride, '/');
            if ($proxyFormat === 'hls') {
                $proxyUrlOverride = "$proxyUrlOverride/api/stream/$id";
                if ($playlist) {
                    $proxyUrlOverride .= "/$playlist";
                }
                return "$proxyUrlOverride/playlist.m3u8";
            } else {
                $proxyUrlOverride = "$proxyUrlOverride/stream/$id/$format";
                if ($playlist) {
                    $proxyUrlOverride .= "/$playlist";
                }
                return $proxyUrlOverride;
            }
        }
        return $proxyFormat === 'hls'
            ? route('stream.hls.playlist', ['encodedId' => $id])
            : route('stream', [
                'encodedId' => $id,
                'format' => $format,
                'playlist' => $playlist
            ]);
    }
}
