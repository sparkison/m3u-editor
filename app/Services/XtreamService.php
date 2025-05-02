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
    protected Playlist $playlist;

    public function init(Playlist $playlist, $retryLimit = 5): bool|self
    {
        if (!$playlist->xtream) {
            return false;
        }
        $this->playlist = $playlist;
        $xtream_config = $playlist->xtream_config;
        $this->server = $xtream_config['url'] ?? '';
        $this->user = $xtream_config['username'] ?? '';
        $this->pass = $xtream_config['password'] ?? '';
        $this->retryLimit = $retryLimit;

        return $this;
    }

    protected function call(string $url)
    {
        if (! $this->playlist) {
            throw new \Exception('Playlist not initialized. Call init() first.');
        }
        $attempts = 0;
        do {
            $user_agent = $this->playlist->user_agent ?? 'VLC/3.0.21 LibVLC/3.0.21';
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
