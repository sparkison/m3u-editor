<?php


use App\Jobs\SyncPlaylistChildren;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Cache\ArrayStore;

uses(RefreshDatabase::class);

beforeAll(function () {
    $dbPath = __DIR__ . '/../../database/database.sqlite';
    if (file_exists($dbPath)) {
        unlink($dbPath);
    }
    touch($dbPath);
    $envPath = __DIR__ . '/../../.env';
    if (! file_exists($envPath)) {
        touch($envPath);
    }
});

beforeEach(function () {
    config([
        'cache.default' => 'array',
        'broadcasting.default' => 'log',
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => ':memory:',
        'queue.default' => 'sync',
    ]);
    Cache::setDefaultDriver('array');
    Cache::flush();
    Model::unguard();
    Queue::fake();
});

it('queues failover additions and syncs to child playlists', function () {

    $user = User::factory()->create();
    $parent = Playlist::factory()->for($user)->create();
    $child = Playlist::factory()->for($user)->create(['parent_id' => $parent->id]);

    $chan1 = Channel::factory()->for($user)->create([
        'playlist_id' => $parent->id,
        'source_id' => 's1',
        'group' => null,
        'group_id' => null,
    ]);
    $chan2 = Channel::factory()->for($user)->create([
        'playlist_id' => $parent->id,
        'source_id' => 's2',
        'group' => null,
        'group_id' => null,
    ]);
    $childChan1 = Channel::factory()->for($user)->create([
        'playlist_id' => $child->id,
        'source_id' => 's1',
        'group' => null,
        'group_id' => null,
    ]);
    $childChan2 = Channel::factory()->for($user)->create([
        'playlist_id' => $child->id,
        'source_id' => 's2',
        'group' => null,
        'group_id' => null,
    ]);

    $chan1->failovers()->create([
        'channel_failover_id' => $chan2->id,
        'user_id' => $user->id,
    ]);

    Queue::assertPushed(SyncPlaylistChildren::class);
    $job = Queue::pushed(SyncPlaylistChildren::class)[0];
    expect($job->playlist->is($parent))->toBeTrue();
    $job->handle();

    $childChan1->refresh();
    expect($childChan1->failovers()->first()->channel_failover_id)->toBe($childChan2->id);
});
