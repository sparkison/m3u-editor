<?php

namespace App\Services;

use App\Settings\GeneralSettings;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ProxyService
{
    public static function m3uProxyEnabled(): bool
    {
        return true;
        // return config('proxy.use_m3u_proxy', false);
    }

    /**
     * Get the proxy URL for a channel
     *
     * @param string|int $id
     * @return string
     */
    public function getProxyUrlForChannel($id, $preview = false)
    {
        $m3uProxy = self::m3uProxyEnabled();
        if ($m3uProxy) {
            if ($preview) {
                return route('m3u-proxy.channel.player', ['id' => $id]);
            }
            return route('m3u-proxy.channel', ['id' => $id]);
        }

        throw new Exception('Direct channel proxying is not supported. Please enable M3U Proxying.');
    }

    /**
     * Get the proxy URL for an episode
     *
     * @param string|int $id
     * @return string
     */
    public function getProxyUrlForEpisode($id, $preview = false)
    {
        $m3uProxy = self::m3uProxyEnabled();
        if ($m3uProxy) {
            if ($preview) {
                return route('m3u-proxy.episode.player', ['id' => $id]);
            }
            return route('m3u-proxy.episode', ['id' => $id]);
        }

        throw new Exception('Direct channel proxying is not supported. Please enable M3U Proxying.');
    }
}
