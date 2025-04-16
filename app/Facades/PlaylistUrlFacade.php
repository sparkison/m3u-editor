<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array getUrls($playlist)
 * @method static boolean mediaFlowProxyEnabled()
 * @method static array getMediaFlowSettings()
 * @method static array getMediaFlowProxyServerUrl()
 * @method static string getMediaFlowProxyUrls()
 */
class PlaylistUrlFacade extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'playlistUrl';
    }
}
