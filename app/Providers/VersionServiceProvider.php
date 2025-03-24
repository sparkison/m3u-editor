<?php

namespace App\Providers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;

class VersionServiceProvider extends ServiceProvider
{
    public static string $cacheKey = 'app.remoteVersion';

    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }

    public static function updateAvailable(): bool
    {
        $remoteVersion = self::getRemoteVersion();
        if ($remoteVersion) {
            $installedVersion = self::getVersion();
            return version_compare($installedVersion, $remoteVersion, '<');
        }
        return false;
    }

    public static function getVersion(): string
    {
        return config('dev.version');
    }

    public static function getRemoteVersion($refresh = false): string
    {
        // If using redis, may not be initialized yet, so catch the exception
        try {
            $remoteVersion = Cache::get(self::$cacheKey);
        } catch (\Exception $e) {
            $remoteVersion = null;
        }
        if ($remoteVersion === null || $refresh) {
            $remoteVersion = '';
            try {
                $response = Http::get('https://raw.githubusercontent.com/sparkison/m3u-editor/refs/heads/master/config/dev.php');
                if ($response->ok()) {
                    $results = $response->body();
                    preg_match("/'version'\s*=>\s*'([^']+)'/", $results, $matches);
                    if (!empty($matches[1])) {
                        $remoteVersion = $matches[1];
                        Cache::put(self::$cacheKey, $remoteVersion, 60 * 5);
                    }
                }
            } catch (\Exception $e) {
                // Ignore
            }
        }
        return $remoteVersion;
    }
}
