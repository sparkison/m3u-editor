<?php

namespace Tests\Unit;

use App\Jobs\MergeChannels;
use App\Models\Channel;
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
        // Create a user
        $user = User::factory()->create();

        // Create channels
        $channel1 = Channel::factory()->create(['stream_id' => 'stream1', 'user_id' => $user->id]);
        $channel2 = Channel::factory()->create(['stream_id' => 'stream1', 'user_id' => $user->id]);
        $channel3 = Channel::factory()->create(['stream_id' => '', 'user_id' => $user->id]);
        $channel4 = Channel::factory()->create(['stream_id' => null, 'user_id' => $user->id]);

        $channels = new Collection([$channel1, $channel2, $channel3, $channel4]);

        // Dispatch the job
        MergeChannels::dispatch($channels, $user);

        // Assert that only the channels with the same stream_id were merged
        $this->assertDatabaseCount('channel_failovers', 1);
    }
}
