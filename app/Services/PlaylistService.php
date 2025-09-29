<?php

namespace App\Services;

use App\Models\Playlist;
use App\Models\MergedPlaylist;
use App\Models\CustomPlaylist;
use App\Models\PlaylistAlias;
use App\Models\PlaylistAuth;
use App\Settings\GeneralSettings;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class PlaylistService
{
    /**
     * Get URLs for the given playlist
     *
     * @param  Playlist|MergedPlaylist|CustomPlaylist|PlaylistAlias $playlist
     * @return array
     */
    public static function getUrls($playlist)
    {
        // Get the first enabled auth (URLs can only contain one set of credentials)
        $playlistAuth = null;
        if (method_exists($playlist, 'playlistAuths')) {
            $playlistAuth = $playlist->playlistAuths()->where('enabled', true)->first();
        } else if ($playlist instanceof PlaylistAlias) {
            // If PlaylistAlias, check if direct authentication is set
            $playlistAuth = $playlist->username && $playlist->password
                ? (object) ['username' => $playlist->username, 'password' => $playlist->password]
                : null;
        }
        $auth = null;
        if ($playlistAuth) {
            $auth = '?username=' . $playlistAuth->username . '&password=' . $playlistAuth->password;
        }

        // Get the base URLs
        if ($playlist->short_urls_enabled) {
            $shortUrls = collect($playlist->short_urls)->keyBy('type');

            $m3uData = $shortUrls->get('m3u');
            $hdhrData = $shortUrls->get('hdhr');
            $epgData = $shortUrls->get('epg');
            $epgZipData = $shortUrls->get('epg_zip');

            $m3uUrl = $m3uData ? url('/s/' . $m3uData['key']) : null;
            $hdhrUrl = $hdhrData ? url('/s/' . $hdhrData['key']) : null;
            $epgUrl = $epgData ? url('/s/' . $epgData['key']) : null;

            // Since zipped url was added later, it might not be present in the short urls
            // Default to the route if not found
            $epgZipUrl = $epgZipData
                ? url('/s/' . $epgZipData['key'])
                : route('epg.generate.compressed', ['uuid' => $playlist->uuid]);
        } else {
            $m3uUrl = route('playlist.generate', ['uuid' => $playlist->uuid]);
            $hdhrUrl = route('playlist.hdhr.overview', ['uuid' => $playlist->uuid]);
            $epgUrl = route('epg.generate', ['uuid' => $playlist->uuid]);
            $epgZipUrl = route('epg.generate.compressed', ['uuid' => $playlist->uuid]);
        }

        // If auth set, append auth parameters to the URLs
        if ($auth) {
            if ($m3uUrl) $m3uUrl .= $auth;
            if ($hdhrUrl) $hdhrUrl .= $auth;
        }

        // Return the results
        return [
            'm3u' => $m3uUrl,
            'hdhr' => $hdhrUrl,
            'epg' => $epgUrl,
            'epg_zip' => $epgZipUrl,
            'authEnabled' => $playlistAuth ? true : false,
        ];
    }

    /**
     * Get Xtream API info for the given playlist
     *
     * @param  Playlist|MergedPlaylist|CustomPlaylist $playlist
     * @return array
     */
    public static function getXtreamInfo($playlist)
    {
        // For Xtream API, we use the playlist UUID as the password
        // and the user's name as the username. This is valid of all playlist types.
        $auth = [
            'username' => $playlist->user->name,
            'password' => $playlist->uuid,
        ];
        if ($playlist instanceof PlaylistAlias) {
            // For PlaylistAlias, override default auth if set
            if ($playlist->username && $playlist->password) {
                $auth = [
                    'username' => $playlist->username,
                    'password' => $playlist->password,
                ];
            }
        }

        // Return the results
        return [
            'url' => url(''), // Base URL of the application
            ...$auth
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
        // Get the first enabled auth (URLs can only contain one set of credentials)
        if (method_exists($playlist, 'playlistAuths')) {
            $playlistAuth = $playlist->playlistAuths()->where('enabled', true)->first();
        } else if ($playlist instanceof PlaylistAlias) {
            // If PlaylistAlias, check if direct authentication is set
            $playlistAuth = $playlist->username && $playlist->password
                ? (object) ['username' => $playlist->username, 'password' => $playlist->password]
                : null;
        }
        $auth = null;
        if ($playlistAuth) {
            $auth = '?username=' . $playlistAuth->username . '&password=' . $playlistAuth->password;
        }

        $settings = $this->getMediaFlowSettings();
        $proxyUrl = rtrim($settings['mediaflow_proxy_url'], '/');
        if ($settings['mediaflow_proxy_port']) {
            $proxyUrl .= ':' . $settings['mediaflow_proxy_port'];
        }

        // Example structure: http://localhost:8888/proxy/hls/manifest.m3u8?d=YOUR_M3U_EDITOR_PLAYLIST_URL&api_password=YOUR_PROXY_API_PASSWORD
        $playlistRoute = route('playlist.generate', ['uuid' => $playlist->uuid]);
        $m3uUrl = $proxyUrl . '/proxy/hls/manifest.m3u8?d=' . urlencode($playlistRoute);

        // Check if we're adding user-agent headers
        if ($settings['mediaflow_proxy_playlist_user_agent']) {
            $m3uUrl .= '&h_user-agent=' . urlencode($playlist->user_agent);
        } else if ($settings['mediaflow_proxy_user_agent']) {
            $m3uUrl .= '&h_user-agent=' . urlencode($settings['mediaflow_proxy_user_agent']);
        }
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
     * Resolve a playlist by its UUID
     *
     * @param  string $uuid
     * @return Playlist|MergedPlaylist|CustomPlaylist|PlaylistAlias|null
     */
    public function resolvePlaylistByUuid($uuid)
    {
        // First try to find primary playlist
        $playlist = Playlist::where('uuid', $uuid)->first();
        if ($playlist) {
            return $playlist;
        }

        // Then try merged playlist
        $playlist = MergedPlaylist::where('uuid', $uuid)->first();
        if ($playlist) {
            return $playlist;
        }

        // Then try custom playlist
        $playlist = CustomPlaylist::where('uuid', $uuid)->first();
        if ($playlist) {
            return $playlist;
        }

        // Finally try playlist alias
        $alias = PlaylistAlias::where('uuid', $uuid)->where('enabled', true)->first();
        if ($alias) {
            return $alias; // Return the alias itself, not the underlying playlist
        }

        return null;
    }

    public static function getChannelBaseUrl(Playlist|PlaylistAlias $source, $channelId): string
    {
        $config = $source instanceof PlaylistAlias
            ? $source->getEffectiveXtreamConfig()
            : $source->xtream_config;

        if (!$config) {
            return '';
        }

        $baseUrl = rtrim($config['url'], '/');
        $username = $config['username'];
        $password = $config['password'];

        return "{$baseUrl}/live/{$username}/{$password}/{$channelId}";
    }

    public static function getSeriesBaseUrl(Playlist|PlaylistAlias $source, $seriesId): string
    {
        $config = $source instanceof PlaylistAlias
            ? $source->getEffectiveXtreamConfig()
            : $source->xtream_config;

        if (!$config) {
            return '';
        }

        $baseUrl = rtrim($config['url'], '/');
        $username = $config['username'];
        $password = $config['password'];

        return "{$baseUrl}/series/{$username}/{$password}/{$seriesId}";
    }

    public static function makeFilesystemSafe(string $name): string
    {
        // Replace filesystem-unsafe characters but preserve Unicode characters
        $unsafe = ['/', '\\', ':', '*', '?', '"', '<', '>', '|', "\0"];
        $safe = str_replace($unsafe, ' ', $name);

        // Remove multiple spaces and trim
        $safe = preg_replace('/\s+/', ' ', trim($safe));

        // Remove leading/trailing dots (Windows limitation)
        $safe = trim($safe, '. ');

        return $safe ?: 'Unnamed';
    }

    /**
     * Authenticate a playlist request
     *
     * @param  string $username
     * @param  string $password
     * @return array|bool [Playlist|MergedPlaylist|CustomPlaylist|null, string $authMethod, string $username, string $password] or false on failure
     */
    public function authenticate($username, $password): array|bool
    {
        if (empty($username) || empty($password)) {
            return false;
        }

        $playlist = null;
        $authMethod = 'none';

        // Method 1: Try to authenticate using PlaylistAuth credentials
        $playlistAuth = PlaylistAuth::where('username', $username)
            ->where('password', $password)
            ->where('enabled', true)
            ->first();

        if ($playlistAuth) {
            $playlist = $playlistAuth->getAssignedModel();
            if ($playlist) {
                // Load necessary relationships for the playlist
                $playlist->load([
                    'user',
                ]);
                $authMethod = 'playlist_auth';
            }
        }

        // Method 2: Fall back to original authentication:
        //      (username = playlist owner, password = playlist UUID)
        if (!$playlist) {
            // Try to find playlist by UUID (password parameter)
            try {
                $playlist = Playlist::with([
                    'user',
                ])->where('uuid', $password)->firstOrFail();

                // Verify username matches playlist owner's name
                if ($playlist->user->name === $username) {
                    $authMethod = 'owner_auth';
                } else {
                    $playlist = null;
                }
            } catch (ModelNotFoundException $e) {
                // Try MergedPlaylist
                try {
                    $playlist = MergedPlaylist::with([
                        'user',
                    ])->where('uuid', $password)->firstOrFail();

                    // Verify username matches playlist owner's name
                    if ($playlist->user->name === $username) {
                        $authMethod = 'owner_auth';
                    } else {
                        $playlist = null;
                    }
                } catch (ModelNotFoundException $e) {
                    // Try CustomPlaylist
                    try {
                        $playlist = CustomPlaylist::with([
                            'user',
                        ])->where('uuid', $password)->firstOrFail();

                        // Verify username matches playlist owner's name
                        if ($playlist->user->name === $username) {
                            $authMethod = 'owner_auth';
                        } else {
                            $playlist = null;
                        }
                    } catch (ModelNotFoundException $e) {
                        // Try PlaylistAlias
                        try {
                            $playlist = PlaylistAlias::with([
                                'user',
                                'playlist',
                                'customPlaylist'
                            ])->where('uuid', $password)
                                ->where('enabled', true)
                                ->firstOrFail();

                            // Verify username matches playlist alias owner's name
                            if ($playlist->user->name === $username) {
                                $authMethod = 'owner_auth';
                            } else {
                                $playlist = null;
                            }
                        } catch (ModelNotFoundException $e) {
                            // No playlist found
                        }
                    }
                }
            }
        }

        return [
            $playlist,
            $authMethod,
            $username,
            $password
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
            'mediaflow_proxy_user_agent' => null,
            'mediaflow_proxy_playlist_user_agent' => null,
        ];
        try {
            $settings = [
                'mediaflow_proxy_url' => $userPreferences->mediaflow_proxy_url ?? $settings['mediaflow_proxy_url'],
                'mediaflow_proxy_port' => $userPreferences->mediaflow_proxy_port ?? $settings['mediaflow_proxy_port'],
                'mediaflow_proxy_password' => $userPreferences->mediaflow_proxy_password ?? $settings['mediaflow_proxy_password'],
                'mediaflow_proxy_user_agent' => $userPreferences->mediaflow_proxy_user_agent ?? $settings['mediaflow_proxy_user_agent'],
                'mediaflow_proxy_playlist_user_agent' => $userPreferences->mediaflow_proxy_playlist_user_agent ?? $settings['mediaflow_proxy_playlist_user_agent'],
            ];
        } catch (Exception $e) {
            // Ignore
        }
        return $settings;
    }
}
