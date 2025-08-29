<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array getUrls(Playlist|MergedPlaylist|CustomPlaylist $playlist)
 * @method static boolean mediaFlowProxyEnabled(Playlist|MergedPlaylist|CustomPlaylist $playlist)
 * @method static string getMediaFlowProxyUrls(Playlist|MergedPlaylist|CustomPlaylist $playlist)
 * @method static array getMediaFlowSettings()
 * @method static array getMediaFlowProxyServerUrl()
 * @method static array|bool authenticate($username, $password) // [Playlist|MergedPlaylist|CustomPlaylist|null, string $authMethod, string $username, string $password] or false on failure
 * @method static Playlist|MergedPlaylist|CustomPlaylist|null resolvePlaylistByUuid(string $uuid)
 */
class PlaylistFacade extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'playlist';
    }
}
