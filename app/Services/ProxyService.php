<?php

namespace App\Services;

class ProxyService
{
    /**
     * Get the proxy URL for a channel
     *
     * @param string|int $id
     * @return string
     */
    public function getProxyUrlForChannel($id)
    {
        $proxyUrlOverride = config('proxy.url_override');
        $id = rtrim(base64_encode($id), '=');
        if ($proxyUrlOverride) {
            $proxyUrlOverride = rtrim($proxyUrlOverride, '/');
            return "$proxyUrlOverride/stream/$id";
        }
        return route('stream', ['id' => $id]);
    }
}
