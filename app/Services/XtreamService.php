<?php

namespace App\Services;

use App\Models\Playlist;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class XtreamService
{
    protected string $server;
    protected string $user;
    protected string $pass;
    protected int $retryLimit;
    protected Playlist|null $playlist;
    protected array|null $xtream_config;

    public function init(
        Playlist|null $playlist = null,
        array|null $xtream_config = null,
        $retryLimit = 5
    ): bool|self {
        // If Playlist, and not an xtream playlist, return false
        if ($playlist && !$playlist->xtream) {
            return false;
        }

        // Set Playlist and Xtream config
        $this->playlist = $playlist;
        $this->xtream_config = $xtream_config;

        // Setup server, user, and pass
        if ($playlist) {
            $config = $playlist->xtream_config;
            $this->server = $config['url'] ?? '';
            $this->user = $config['username'] ?? '';
            $this->pass = $config['password'] ?? '';
        } else if ($xtream_config) {
            $this->server = $xtream_config['url'] ?? '';
            $this->user = $xtream_config['username'] ?? '';
            $this->pass = $xtream_config['password'] ?? '';
        } else {
            return false;
        }

        $this->retryLimit = $retryLimit;

        return $this;
    }

    protected function call(string $url)
    {
        if (! ($this->playlist || $this->xtream_config)) {
            throw new \Exception('Config not initialized. Call init() first with Playlist or Xtream config array.');
        }
        $attempts = 0;
        do {
            $user_agent = $this->playlist?->user_agent ?? 'VLC/3.0.21 LibVLC/3.0.21';
            $response = Http::timeout(10)
                ->withHeaders(['User-Agent' => $user_agent])
                ->get($url);

            if ($response->ok()) {
                return $response->json();
            }

            $attempts++;
            sleep(1);
        } while ($attempts < $this->retryLimit);

        $response->throw(); // if we exhausted retries, let it bubble up
    }

    protected function makeUrl(string $action, array $extra = []): string
    {
        $params = array_merge([
            'username' => $this->user,
            'password' => $this->pass,
            'action'   => $action,
        ], $extra);

        return Str::start($this->server, 'http://')
            . '/player_api.php?' . http_build_query($params);
    }

    public function authenticate(): array
    {
        $url = $this->server
            . "/player_api.php?username={$this->user}&password={$this->pass}";
        return $this->call($url)['user_info'] ?? [];
    }

    public function getVodCategories(): array
    {
        return $this->call($this->makeUrl('get_vod_categories'));
    }

    public function getVodStreams(string $catId): array
    {
        return $this->call($this->makeUrl('get_vod_streams', ['category_id' => $catId]));
    }

    public function getSeriesCategories(): array
    {
        return $this->call($this->makeUrl('get_series_categories'));
    }

    public function getSeries(string $catId): array
    {
        return $this->call($this->makeUrl('get_series', ['category_id' => $catId]));
    }

    public function getSeriesInfo(string $seriesId): array
    {
        return $this->call($this->makeUrl('get_series_info', ['series_id' => $seriesId]));
    }

    public function buildMovieUrl(string $id, string $ext): string
    {
        return "{$this->server}/movie/{$this->user}/{$this->pass}/{$id}.{$ext}";
    }

    public function buildSeriesUrl(string $id, string $ext): string
    {
        return "{$this->server}/series/{$this->user}/{$this->pass}/{$id}.{$ext}";
    }
}
