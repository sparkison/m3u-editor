<?php

use App\Filament\BulkActions\HandlesSourcePlaylist;
use App\Models\Series;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Filament\Forms;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

function makeSeriesFormHandler() {
    return new class {
        use HandlesSourcePlaylist {
            buildSourcePlaylistForm as public;
        }
    };
}

it('hides the source playlist selector when no duplicates exist', function () {
    $handler = makeSeriesFormHandler();

    $user = User::factory()->create();
    actingAs($user);

    $playlist = Playlist::factory()->for($user)->create();
    $series = Series::factory()->for($user)->create([
        'playlist_id'      => $playlist->id,
        'source_series_id' => 1,
        'name'             => 'Series 1',
    ]);

    $records = collect([$series]);

    $form = $handler::buildSourcePlaylistForm($records, 'series', 'source_series_id', 'series');

    expect($form)->toBeEmpty();
});

it('renders one required selector for a single parent-child duplicate pair', function () {
    $handler = makeSeriesFormHandler();

    $user = User::factory()->create();
    actingAs($user);

    $parent = Playlist::factory()->for($user)->create();
    $child  = Playlist::factory()->for($user)->create(['parent_id' => $parent->id]);

    Series::factory()->for($user)->create(['playlist_id' => $parent->id, 'source_series_id' => 1, 'name' => 'A']);
    $childSeries = Series::factory()->for($user)->create(['playlist_id' => $child->id, 'source_series_id' => 1, 'name' => 'A']);

    $form = $handler::buildSourcePlaylistForm(collect([$childSeries]), 'series', 'source_series_id', 'series');

    expect($form)->toHaveCount(1);

    $select = $form[0]->getChildComponents()[0];

    expect($select)->toBeInstanceOf(Forms\Components\Select::class);
    expect($select->isRequired())->toBeTrue();
});

it('renders one selector per duplicated parent-child group', function () {
    $handler = makeSeriesFormHandler();

    $user = User::factory()->create();
    actingAs($user);

    $parent = Playlist::factory()->for($user)->create();
    $childA = Playlist::factory()->for($user)->create(['parent_id' => $parent->id]);
    $childB = Playlist::factory()->for($user)->create(['parent_id' => $parent->id]);

    Series::factory()->for($user)->create(['playlist_id' => $parent->id, 'source_series_id' => 1, 'name' => 'A']);
    $childSeriesA = Series::factory()->for($user)->create(['playlist_id' => $childA->id, 'source_series_id' => 1, 'name' => 'A']);
    $childSeriesB = Series::factory()->for($user)->create(['playlist_id' => $childB->id, 'source_series_id' => 1, 'name' => 'A']);

    $form = $handler::buildSourcePlaylistForm(collect([$childSeriesA, $childSeriesB]), 'series', 'source_series_id', 'series');

    expect($form)->toHaveCount(2);

    foreach ($form as $fieldset) {
        $select = $fieldset->getChildComponents()[0];
        expect($select)->toBeInstanceOf(Forms\Components\Select::class);
        expect($select->isRequired())->toBeTrue();
    }
});


