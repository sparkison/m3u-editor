<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array getUrls(Playlist|MergedPlaylist|CustomPlaylist $playlist)
 * @method static boolean mediaFlowProxyEnabled(Playlist|MergedPlaylist|CustomPlaylist $playlist)
 * @method static string getMediaFlowProxyUrls(Playlist|MergedPlaylist|CustomPlaylist $playlist)
 * @method static array getMediaFlowSettings()
 * @method static array getMediaFlowProxyServerUrl()
 */
class PlaylistUrlFacade extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'playlistUrl';
    }
}
