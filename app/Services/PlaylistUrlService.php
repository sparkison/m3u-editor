<?php

namespace App\Services;

use App\Models\Playlist;
use App\Models\MergedPlaylist;
use App\Models\CustomPlaylist;
use App\Settings\GeneralSettings;
use Exception;

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
            $m3uUrl = url('/s/' . $shortUrls->get('m3u')['key']);
            $hdhrUrl = url('/s/' . $shortUrls->get('hdhr')['key']);
            $epgUrl = url('/s/' . $shortUrls->get('epg')['key']);;
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

    /**
     * Get the media flow proxy server URL
     * 
     * @return string
     */
    public function getMediaFlowProxyServerUrl()
    {
        $settings = $this->getMediaFlowSettings();
        $proxyUrl = rtrim($settings['mediaflow_proxy_url'], '/');
        if ($settings['mediaflow_proxy_port']) {
            $proxyUrl .= ':' . $settings['mediaflow_proxy_port'];
        }
        return $proxyUrl;
    }

    /**
     * Get the media flow proxy URLs for the given playlist
     * 
     * @param  Playlist|MergedPlaylist|CustomPlaylist $playlist
     * @return array
     */
    public function getMediaFlowProxyUrls($playlist)
    {
        // Get the first auth
        $playlistAuth = $playlist->playlistAuths()->where('enabled', true)->first();
        $auth = null;
        if ($playlistAuth) {
            $auth = '&username=' . $playlistAuth->username . '&password=' . $playlistAuth->password;
        }

        $settings = $this->getMediaFlowSettings();
        $proxyUrl = rtrim($settings['mediaflow_proxy_url'], '/');
        if ($settings['mediaflow_proxy_port']) {
            $proxyUrl .= ':' . $settings['mediaflow_proxy_port'];
        }

        // Example structure: http://localhost:8888/proxy/hls/manifest.m3u8?d=YOUR_M3U_EDITOR_PLAYLIST_URL&api_password=YOUR_PROXY_API_PASSWORD
        $m3uUrl = $proxyUrl . '/proxy/hls/manifest.m3u8?d=' . route('playlist.generate', ['uuid' => $playlist->uuid]);
        $m3uUrl .= '&api_password=' . $settings['mediaflow_proxy_password'];

        // If auth set, append auth parameters to the URLs
        if ($auth) {
            $m3uUrl .= $auth;
        }

        // Return the results
        return [
            'm3u' => $m3uUrl,
            'authEnabled' => $playlistAuth ? true : false,
        ];
    }

    /**
     * Determine if the media flow proxy is enabled
     * 
     * @return boolean
     */
    public function mediaFlowProxyEnabled()
    {
        return $this->getMediaFlowSettings()['mediaflow_proxy_url'] !== null;
    }

    /**
     * Get the media flow settings
     * 
     * @return array
     */
    public function getMediaFlowSettings(): array
    {
        // Get user preferences
        $userPreferences = app(GeneralSettings::class);
        $settings = [
            'mediaflow_proxy_url' => null,
            'mediaflow_proxy_port' => null,
            'mediaflow_proxy_password' => null,
        ];
        try {
            $settings = [
                'mediaflow_proxy_url' => $userPreferences->mediaflow_proxy_url ?? $settings['mediaflow_proxy_url'],
                'mediaflow_proxy_port' => $userPreferences->mediaflow_proxy_port ?? $settings['mediaflow_proxy_port'],
                'mediaflow_proxy_password' => $userPreferences->mediaflow_proxy_password ?? $settings['mediaflow_proxy_password'],
            ];
        } catch (Exception $e) {
            // Ignore
        }
        return $settings;
    }
}
