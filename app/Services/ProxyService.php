<?php

namespace App\Services;

class ProxyService
{
    /**
     * Get the proxy URL for a channel
     *
     * @param string|int $id
     * @param string $format
     * @return string
     */
    public function getProxyUrlForChannel($id, $format = 'ts')
    {
        $proxyUrlOverride = config('proxy.url_override');
        $proxyFormat = $format ?? config('proxy.proxy_format', 'ts');
        $id = rtrim(base64_encode($id), '=');
        if ($proxyUrlOverride) {
            $proxyUrlOverride = rtrim($proxyUrlOverride, '/');
            if ($proxyFormat === 'hls') {
                return "$proxyUrlOverride/api/stream/$id/playlist.m3u8";
            } else {
                return "$proxyUrlOverride/stream/$id";
            }
        }

        return $proxyFormat === 'hls'
            ? route('stream.hls.playlist', [
                'encodedId' => $id
            ])
            : route('stream', [
                'encodedId' => $id,
                'format' => $format
            ]);
    }

    /**
     * Get the proxy URL for an episode
     *
     * @param string|int $id
     * @param string $format
     * @return string
     */
    public function getProxyUrlForEpisode($id, $format = 'ts')
    {
        $proxyUrlOverride = config('proxy.url_override');
        $proxyFormat = $format ?? config('proxy.proxy_format', 'ts');
        $id = rtrim(base64_encode($id), '=');
        if ($proxyUrlOverride) {
            $proxyUrlOverride = rtrim($proxyUrlOverride, '/');
            if ($proxyFormat === 'hls') {
                return "$proxyUrlOverride/api/stream/e/$id/playlist.m3u8";
            } else {
                return "$proxyUrlOverride/stream/e/$id";
            }
        }

        return $proxyFormat === 'hls'
            ? route('stream.hls.episode', [
                'encodedId' => $id
            ])
            : route('stream.episode', [
                'encodedId' => $id,
                'format' => $format
            ]);
    }
}
