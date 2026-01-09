<?php

namespace Tests\Unit;

use App\Jobs\MergeChannels;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class MergeChannelsTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_does_not_merge_channels_with_empty_stream_ids()
    {
        // Create a user and playlist
        $user = User::factory()->create();
        $playlist = Playlist::factory()->for($user)->create();

        // Create channels for the playlist with same stream_id
        $channel1 = Channel::factory()->create(['stream_id' => 'stream1', 'user_id' => $user->id, 'playlist_id' => $playlist->id]);
        $channel2 = Channel::factory()->create(['stream_id' => 'stream1', 'user_id' => $user->id, 'playlist_id' => $playlist->id]);
        // Channels with empty stream_ids should not be merged
        $channel3 = Channel::factory()->create(['stream_id' => '', 'user_id' => $user->id, 'playlist_id' => $playlist->id]);
        $channel4 = Channel::factory()->create(['stream_id' => null, 'user_id' => $user->id, 'playlist_id' => $playlist->id]);

        // Create playlists collection as expected by MergeChannels constructor
        $playlists = collect([['playlist_failover_id' => $playlist->id]]);

        // Run the job synchronously (dispatchSync instead of dispatch)
        MergeChannels::dispatchSync($user, $playlists, $playlist->id);

        // Assert that only the channels with the same non-empty stream_id were merged
        // channel1 and channel2 have same stream_id, so there should be 1 failover entry
        $this->assertDatabaseCount('channel_failovers', 1);
    }
}
