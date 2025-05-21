<?php

namespace App\Console\Commands;

use App\Models\Playlist;
use App\Services\XtreamService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Xtream2Strm extends Command
{
    protected $signature = 'app:xtream-generate {playlist?}';
    protected $description = 'Generate .strm files from Xtream API';

    protected Playlist $playlist;

    public function handle(XtreamService $xtream)
    {
        $playlistId = $this->argument('playlist');
        if ($playlistId) {
            $playlist = Playlist::find($playlistId);
        } else {
            $playlists = Playlist::where('xtream', true)->get();
            $playlist = $this->choice('Select an Xtream enabled playlist to generate .strm files for:', $playlists->pluck('name')->toArray());
            $playlist = $playlists->where('name', $playlist)->first();
        }

        if (! $playlist) {
            $this->error('Playlist not found.');
            return 1;
        }
        if (! $playlist->xtream) {
            $this->error('Playlist is not Xtream enabled.');
            return 1;
        }

        $this->playlist = $playlist;
        $xtream_config = $playlist->xtream_config;

        $this->info('Xtream helper');
        $this->info('Connecting to: ' . $xtream_config['url'] . '...');

        $xtream = $xtream->init(
            playlist: $playlist,
            retryLimit: 5
        );
        if (! $xtream) {
            $this->error('Xtream service initialization failed.');
            return 1;
        }
        $userInfo = $xtream->authenticate();
        if (! ($userInfo['auth'] ?? false)) {
            $this->error('Authentication failed.');
            return 1;
        }

        $choice = $this->choice('Type S(eries) or M(ovies)', ['S', 'M'], 0);
        match (Str::upper($choice)) {
            'S' => $this->processSeries($xtream),
            'M' => $this->processMovies($xtream),
        };

        return 0;
    }

    protected function processMovies(XtreamService $xtream)
    {
        $cats = $xtream->getVodCategories();
        $map = collect($cats)->pluck('category_id', 'category_name')->toArray();
        $catName = $this->choice('Pick a Movie Category', array_keys($map));
        $catId = $map[$catName];

        $movies = $xtream->getVodStreams($catId);
        $movieMap = collect($movies)->pluck('stream_id', 'name')->toArray();
        $pick = $this->choice('Pick a movie or All', array_merge(['All'], array_keys($movieMap)));

        if ($pick === 'All') {
            $this->generateMovies($xtream, $movies, $catName);
        } else {
            $id = $movieMap[$pick];
            $this->generateMovies($xtream, [
                ['name' => $pick, 'stream_id' => $id, 'container_extension' => $movies[array_search($pick, array_column($movies, 'name'))]['container_extension']]
            ], $catName);
        }
    }

    protected function generateMovies(XtreamService $xtream, array $movies, string $catName)
    {
        $folder = Str::of($catName)->replace(' | ', ' - ')->trim();
        foreach ($movies as $movie) {
            $name  = str_replace('/', '', $movie['name']);
            $url = $xtream->buildMovieUrl($movie['stream_id'], $movie['container_extension']);
            $path = config('xtream.output_path') . '/' . $this->playlist->id . '/'
                . config('xtream.dirs.movies') . '/' . $folder;
            $this->writeStrm($path, $name . '.strm', $url);
            $this->info("→ {$name}.strm");
        }
    }

    protected function processSeries(XtreamService $xtream)
    {
        $cats = $xtream->getSeriesCategories();
        $map = collect($cats)->pluck('category_id', 'category_name')->toArray();
        $catName = $this->choice('Pick a Series Category', array_keys($map));
        $catId = $map[$catName];

        $seriesList = $xtream->getSeries($catId);
        $seriesMap = collect($seriesList)->pluck('series_id', 'name')->toArray();
        $pick = $this->choice('Pick a series or All', array_merge(['All'], array_keys($seriesMap)));

        if ($pick === 'All') {
            $this->generateCategorySeries($xtream, $seriesList, $catName);
        } else {
            $seriesId = $seriesMap[$pick];
            $this->generateOneSeries($xtream, $seriesId, $catName);
        }
    }

    protected function generateCategorySeries(XtreamService $xtream, array $seriesList, string $catName)
    {
        foreach ($seriesList as $serie) {
            $this->generateOneSeries($xtream, $serie['series_id'], $catName, false);
        }
    }

    protected function generateOneSeries(XtreamService $xtream, string $seriesId, string $catName, bool $showInfo = true)
    {
        $langIgnore = config('xtream.lang_strip');
        $detail = $xtream->getSeriesInfo($seriesId);
        $info = $detail['info'] ?? [];
        $eps = $detail['episodes'] ?? [];
        $seriesName = Str::of($info['name'])->replace($langIgnore, '')->trim();
        $catFolder = Str::of($catName)->replace(' | ', ' - ')->trim();

        foreach ($eps as $season => $episodes) {
            foreach ($episodes as $ep) {
                $num = str_pad($ep['episode_num'], 2, '0', STR_PAD_LEFT);
                $prefx = "S" . str_pad($season, 2, '0', STR_PAD_LEFT) . "E{$num}";
                $title = preg_match('/S\d{2}E\d{2} - (.*)/', $ep['title'], $m) ? $m[1] : 'Unknown';
                $url = $xtream->buildSeriesUrl($ep['id'], $ep['container_extension']);
                $path = config('xtream.output_path') . '/' . $this->playlist->id . '/'
                    . config('xtream.dirs.series') . "/{$catFolder}/{$seriesName}/Season " . str_pad($season, 2, '0', '0');
                $this->writeStrm($path, "{$prefx} - {$title}.strm", $url);
                $this->info("→ {$prefx} - {$title}.strm");
            }
        }
    }

    protected function writeStrm(string $path, string $filename, string $url)
    {
        Storage::disk('local')->makeDirectory($path);
        Storage::disk('local')->put("{$path}/{$filename}", $url);
    }
}
