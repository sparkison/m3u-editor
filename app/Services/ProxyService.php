<?php

namespace App\Services;

class ProxyService
{
    /**
     * Get the proxy URL for a channel
     *
     * @param string|int $id
     * @param string|null $format
     * @return string
     */
    public function getProxyUrlForChannel($id, $format = null)
    {
        $proxyUrlOverride = config('proxy.url_override');
        $proxyFormat = $format ?? config('proxy.proxy_format', 'mpts');
        $id = rtrim(base64_encode($id), '=');
        if ($proxyUrlOverride) {
            $proxyUrlOverride = rtrim($proxyUrlOverride, '/');
            if ($proxyFormat === 'hls') {
                return "$proxyUrlOverride/api/stream/$id.m3u8";
            } else {
                return "$proxyUrlOverride/stream/$id";
            }
        }
        return $proxyFormat === 'hls'
            ? route('stream.hls.playlist', ['encodedId' => $id])
            : route('stream', ['id' => $id]);
    }
}
