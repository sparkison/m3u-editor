<?php

namespace App\Services;

class ProxyService
{
    /**
     * Get the proxy URL for a channel
     *
     * @param string $id
     * @return string
     */
    public function getProxyUrlForChannel($id)
    {
        $proxyUrlOverride = config('proxy.url_override');
        $id = rtrim(base64_encode($id), '=');
        if ($proxyUrlOverride) {
            $proxyUrlOverride = rtrim($proxyUrlOverride, '/');
            $port = config('app.port');
            if ($port && $port !== 80 && $port !== 443) {
                return "$proxyUrlOverride:$port/stream/$id";
            }
            return "$proxyUrlOverride/stream/$id";
        }
        return route('stream', ['id' => $id]);
    }
}
