<?php

namespace Tests\Unit;

use App\Jobs\MergeChannels;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Mockery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class MergeChannelsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_does_not_merge_channels_with_empty_stream_ids(): void
    {
        $user = User::factory()->create();
        $playlist = Playlist::factory()->create(['user_id' => $user->id]);

        Channel::factory()->create(['stream_id' => 'stream1', 'user_id' => $user->id, 'playlist_id' => $playlist->id]);
        Channel::factory()->create(['stream_id' => 'stream1', 'user_id' => $user->id, 'playlist_id' => $playlist->id]);
        Channel::factory()->create(['stream_id' => '', 'user_id' => $user->id, 'playlist_id' => $playlist->id]);
        Channel::factory()->create(['stream_id' => null, 'user_id' => $user->id, 'playlist_id' => $playlist->id]);

        $job = Mockery::mock(MergeChannels::class, [$user, new Collection([$playlist->id]), $playlist->id])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $job->shouldReceive('sendCompletionNotification');
        $job->handle();

        $this->assertDatabaseCount('channel_failovers', 1);
    }
}
