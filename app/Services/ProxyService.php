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
    public function getProxyUrlForChannel($id, $preview = false)
    {
        if ($preview) {
            return route('m3u-proxy.channel.player', ['id' => $id]);
        }
        return route('m3u-proxy.channel', ['id' => $id]);
    }

    /**
     * Get the proxy URL for an episode
     *
     * @param string|int $id
     * @return string
     */
    public function getProxyUrlForEpisode($id, $preview = false)
    {
        if ($preview) {
            return route('m3u-proxy.episode.player', ['id' => $id]);
        }
        return route('m3u-proxy.episode', ['id' => $id]);
    }
}
