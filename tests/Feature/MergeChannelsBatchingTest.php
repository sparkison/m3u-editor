<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class MergeChannelsBatchingTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Playlist $playlist;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->playlist = Playlist::factory()->for($this->user)->create();
    }

    public function test_merge_channels_job_processes_in_batches()
    {
        // Create channels with the same stream_id
        $channel1 = Channel::factory()->create(['playlist_id' => $this->playlist->id, 'stream_id' => '100', 'user_id' => $this->user->id, 'title' => 'Channel 1']);
        $channel2 = Channel::factory()->create(['playlist_id' => $this->playlist->id, 'stream_id' => '100', 'user_id' => $this->user->id, 'title' => 'Channel 2']);
        $channel3 = Channel::factory()->create(['playlist_id' => $this->playlist->id, 'stream_id' => '200', 'user_id' => $this->user->id, 'title' => 'Channel 3']);
        $channel4 = Channel::factory()->create(['playlist_id' => $this->playlist->id, 'stream_id' => '200', 'user_id' => $this->user->id, 'title' => 'Channel 4']);

        $channels = collect([$channel1, $channel2, $channel3, $channel4]);

        // Mock the Log facade to capture log messages
        Log::shouldReceive('info')
            ->with('Processing group: ' . collect([$channel1, $channel2])->pluck('id')->implode(', '));

        Log::shouldReceive('info')
            ->with('Master channel: ' . $channel1->id);

        Log::shouldReceive('info')
            ->with('Processing group: ' . collect([$channel3, $channel4])->pluck('id')->implode(', '));

        Log::shouldReceive('info')
            ->with('Master channel: ' . $channel3->id);

        // Run the merge job
        (new \App\Jobs\MergeChannels($channels, $this->user))->handle();

        // Assert that failover records were created
        $this->assertDatabaseCount('channel_failovers', 2);
        $this->assertDatabaseHas('channel_failovers', ['channel_id' => $channel1->id, 'channel_failover_id' => $channel2->id]);
        $this->assertDatabaseHas('channel_failovers', ['channel_id' => $channel3->id, 'channel_failover_id' => $channel4->id]);
    }
}
