<?php

namespace App\Services;

use App\Models\Playlist;
use App\Models\MergedPlaylist;
use App\Models\CustomPlaylist;

class PlaylistUrlService
{
    /**
     * Get URLs for the given playlist
     * 
     * @param  Playlist|MergedPlaylist|CustomPlaylist $playlist
     * @return array
     */
    public static function getUrls($playlist)
    {
        // Get the first auth
        $playlistAuth = $playlist->playlistAuths()->where('enabled', true)->first();
        $auth = null;
        if ($playlistAuth) {
            $auth = '?username=' . $playlistAuth->username . '&password=' . $playlistAuth->password;
        }

        // Get the base URLs
        if ($playlist->short_urls_enabled) {
            $shortUrls = collect($playlist->short_urls)->keyBy('type');
            $m3uUrl = $shortUrls->get('m3u')['short_url'];
            $hdhrUrl = $shortUrls->get('hdhr')['short_url'];
            $epgUrl = $shortUrls->get('epg')['short_url'];
        } else {
            $m3uUrl = route('playlist.generate', ['uuid' => $playlist->uuid]);
            $hdhrUrl = route('playlist.hdhr.overview', ['uuid' => $playlist->uuid]);
            $epgUrl = route('epg.generate', ['uuid' => $playlist->uuid]);
        }
        
        // If auth set, append auth parameters to the URLs
        if ($auth) {
            $m3uUrl .= $auth;
            $hdhrUrl .= $auth;
        }

        // Return the results
        return [
            'm3u' => $m3uUrl,
            'hdhr' => $hdhrUrl,
            'epg' => $epgUrl,
            'authEnabled' => $playlistAuth ? true : false,
        ];
    }
}
