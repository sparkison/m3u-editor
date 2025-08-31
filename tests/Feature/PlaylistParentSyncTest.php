<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Jobs\ProcessM3uImport;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PlaylistParentSyncTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! file_exists(database_path('database.sqlite'))) {
            touch(database_path('database.sqlite'));
        }
        if (! file_exists(database_path('jobs.sqlite'))) {
            touch(database_path('jobs.sqlite'));
        }
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite.database' => database_path('database.sqlite')]);
        config(['database.connections.jobs.database' => database_path('jobs.sqlite')]);
        \Artisan::call('migrate:fresh', ['--force' => true]);
        (new AppServiceProvider(app()))->boot();
    }

    public function test_parent_syncs_to_child(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $parent = Playlist::factory()->for($user)->create();
        $child = Playlist::factory()->for($user)->create([
            'parent_playlist_id' => $parent->id,
        ]);

        $group = Group::factory()->for($user)->for($parent)->create();
        Channel::factory()->for($user)->for($parent)->for($group, 'group')->create([
            'name' => 'Parent Channel',
        ]);

        $parent->syncChildPlaylists();

        $this->assertEquals(1, $child->channels()->withAllPlaylists()->count());
        $this->assertEquals('Parent Channel', $child->channels()->withAllPlaylists()->first()->name);
    }

    public function test_unsyncing_child_rebuilds_content(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $parent = Playlist::factory()->for($user)->create();
        $child = Playlist::factory()->for($user)->create([
            'parent_playlist_id' => $parent->id,
        ]);

        $group = Group::factory()->for($user)->for($parent)->create();
        Channel::factory()->for($user)->for($parent)->for($group, 'group')->create();

        $parent->syncChildPlaylists();
        $this->assertEquals(1, $child->channels()->withAllPlaylists()->count());

        $child->update(['parent_playlist_id' => null]);

        $this->assertEquals(0, $child->channels()->withAllPlaylists()->count());
        Queue::assertPushed(ProcessM3uImport::class, fn($job) => $job->playlist->is($child));
    }
}
