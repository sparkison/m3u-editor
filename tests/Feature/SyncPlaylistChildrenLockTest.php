<?php

use App\Jobs\SyncPlaylistChildren;
use App\Models\Playlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

it('releases lock when parent playlist is missing', function () {
    $playlist = Playlist::factory()->create();

    Config::set('cache.default', 'database');

    $lockName = "playlist-sync-children:{$playlist->id}";
    Cache::lock($lockName)->get();

    $job = new SyncPlaylistChildren($playlist);
    $playlist->delete();

    $job->handle();

    expect(Cache::lock($lockName)->get())->toBeTrue();
    Cache::lock($lockName)->forceRelease();
});
