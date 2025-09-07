<?php

use App\Enums\Status;
use App\Events\SyncCompleted;
use App\Jobs\SyncPlaylistChildren;
use App\Models\Playlist;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Cache\ArrayStore;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
 
beforeEach(function () {
    Queue::fake();
    config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
    config(['cache.default' => 'array', 'broadcasting.default' => 'log']);
    Cache::swap(new Illuminate\Cache\Repository(new ArrayStore()));
    Cache::flush();
});

it('dispatches child sync only after all child playlists complete successfully', function () {
    $parent = Playlist::factory()->create(['processing' => false]);
    $child1 = Playlist::factory()->create([
        'parent_id' => $parent->id,
        'status' => Status::Completed,
    ]);
    $child2 = Playlist::factory()->create([
        'parent_id' => $parent->id,
        'status' => Status::Failed,
    ]);

    event(new SyncCompleted($child1));

    Queue::assertNotPushed(SyncPlaylistChildren::class);

    $child2->status = Status::Completed;
    $child2->save();
    event(new SyncCompleted($child2));

    Queue::assertPushed(SyncPlaylistChildren::class, fn ($job) => $job->playlist->is($parent));
    Queue::assertPushedTimes(SyncPlaylistChildren::class, 1);
});

it('dispatches child sync only once when children complete simultaneously', function () {
    $parent = Playlist::factory()->create(['processing' => false]);
    $child1 = Playlist::factory()->create([
        'parent_id' => $parent->id,
        'status' => Status::Completed,
    ]);
    $child2 = Playlist::factory()->create([
        'parent_id' => $parent->id,
        'status' => Status::Completed,
    ]);

    event(new SyncCompleted($child1));
    event(new SyncCompleted($child2));

    Queue::assertPushedTimes(SyncPlaylistChildren::class, 1);
});

