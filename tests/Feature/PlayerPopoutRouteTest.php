<?php

namespace Tests\Feature;

use Tests\TestCase;

class PlayerPopoutRouteTest extends TestCase
{
    public function test_returns_404_for_player_popout_without_stream_url(): void
    {
        $this->get('/player/popout')
            ->assertNotFound();
    }

    public function test_renders_player_popout_with_provided_stream_data(): void
    {
        $this->get('/player/popout?url=http://example.test/stream.ts&format=ts&title=Test+Channel')
            ->assertOk()
            ->assertSee('Test Channel - Player', false)
            ->assertSee('id="popout-player"', false)
            ->assertSee('data-url="http://example.test/stream.ts"', false)
            ->assertSee('data-format="ts"', false);
    }
}
