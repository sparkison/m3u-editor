<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PlaylistPairingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sqlite.database' => ':memory:']);
        config(['database.connections.jobs.database' => ':memory:']);
        \Artisan::call('migrate', ['--force' => true]);
        (new AppServiceProvider(app()))->boot();
    }

    public function test_pairing_syncs_channels(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $first = Playlist::factory()->for($user)->create();
        $second = Playlist::factory()->for($user)->create();

        $this->assertTrue($first->pairWith($second));

        $group = Group::factory()->for($user)->for($first)->create();
        Channel::factory()->for($user)->for($first)->for($group, 'group')->create([
            'name' => 'Example Channel',
        ]);
        $first->syncPairedPlaylist();

        $this->assertEquals(1, $second->channels()->count());
        $this->assertEquals('Example Channel', $second->channels()->first()->name);
    }

    public function test_pairing_requires_identical_playlists(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $first = Playlist::factory()->for($user)->create();
        $second = Playlist::factory()->for($user)->create();

        Channel::factory()->for($user)->for($first)->create();

        $this->assertFalse($first->pairWith($second));
        $this->assertNull($first->paired_playlist_id);
        $this->assertNull($second->paired_playlist_id);
    }
}
