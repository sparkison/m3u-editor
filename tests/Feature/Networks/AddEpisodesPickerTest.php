<?php

use App\Livewire\Filament\Networks\EpisodePicker;
use App\Models\Episode;
use App\Models\Network;
use App\Models\NetworkContent;
use App\Models\Series;
use App\Models\Playlist;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->playlist = Playlist::factory()->create();
    $this->series = Series::factory()->for($this->playlist)->create();

    Episode::factory()->for($this->series)->count(40)->sequence(fn ($s) => ['season' => 1, 'episode_num' => $s + 1])->create();

    $this->network = Network::factory()->create([
        'playlist_id' => $this->playlist->id,
        'media_server_integration_id' => null,
    ]);

    // Link media server integration via playlist relationship to satisfy guard
    $this->network->mediaServerIntegration()->associate($this->playlist)->saveQuietly();
});

it('paginates and searches episodes and can bulk add them', function () {
    $component = Livewire::test(EpisodePicker::class, ['network' => $this->network]);

    // select series and ensure first page renders
    $component->set('seriesId', $this->series->id)
        ->assertSee('S1E1')
        ->assertSee('S1E10')
        ->assertSee('Showing');

    // search narrows results
    $component->set('search', 'S1E2')
        ->assertSee('S1E2')
        ->assertDontSee('S1E5');

    // select two episodes and add
    $ids = Episode::where('series_id', $this->series->id)->limit(2)->pluck('id')->all();

    $component->set('selected', $ids)
        ->call('addSelected');

    foreach ($ids as $id) {
        $this->assertDatabaseHas('network_contents', [
            'network_id' => $this->network->id,
            'contentable_type' => Episode::class,
            'contentable_id' => $id,
        ]);
    }

    // adding same IDs again results in no duplicates
    $component->set('selected', $ids)
        ->call('addSelected');

    $this->assertEquals(2, NetworkContent::where('network_id', $this->network->id)->count());
});
