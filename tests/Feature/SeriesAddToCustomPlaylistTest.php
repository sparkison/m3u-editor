<?php

use App\Filament\Resources\SeriesResource;
use App\Models\CustomPlaylist;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

function getSeriesAddAction(EloquentCollection $records)
{
    $bulkActions = SeriesResource::getTableBulkActions();
    $bulkActionGroup = $bulkActions[0];
    $addAction = collect($bulkActionGroup->getActions())->first(fn($action) => $action->getName() === 'add');
    $addAction->records($records);
    return $addAction;
}

it('adds series without source selector when no duplicates exist', function () {
    $user = User::factory()->create();
    actingAs($user);

    $playlist = Playlist::factory()->for($user)->create();
    $series = Series::factory()->for($user)->for($playlist)->create([
        'source_series_id' => 1,
        'name' => 'S1',
    ]);

    $custom = CustomPlaylist::factory()->for($user)->create();

    $records = new EloquentCollection([$series]);

    Queue::fake();

    $addAction = getSeriesAddAction($records);
    $addAction->call([
        'playlist' => $custom->id,
        'category' => null,
    ]);

    expect($custom->series()->pluck('id'))->toContain($series->id);
});

it('requires source playlist for duplicates and applies overrides (series)', function () {
    $user = User::factory()->create();
    actingAs($user);

    $parent = Playlist::factory()->for($user)->create();
    $child  = Playlist::factory()->for($user)->create(['parent_id' => $parent->id]);

    $parentA = Series::factory()->for($user)->for($parent)->create(['source_series_id' => 1, 'name' => 'A']);
    $childA  = Series::factory()->for($user)->for($child)->create(['source_series_id' => 1, 'name' => 'A']);
    $parentB = Series::factory()->for($user)->for($parent)->create(['source_series_id' => 2, 'name' => 'B']);
    $childB  = Series::factory()->for($user)->for($child)->create(['source_series_id' => 2, 'name' => 'B']);

    $records = new EloquentCollection([$childA, $childB]);

    Queue::fake();
    $pairKey = $parent->id . '-' . $child->id;

    $custom = CustomPlaylist::factory()->for($user)->create();

    $addAction = getSeriesAddAction($records);
    $addAction->call([
        'playlist' => $custom->id,
        'category' => null,
        'source_playlists' => [$pairKey => $parent->id],
        'source_playlists_items' => [
            $pairKey => [
                $childB->id => $child->id,
            ],
        ],
    ]);

    expect($custom->series()->pluck('id')->all())
        ->toEqualCanonicalizing([$parentA->id, $childB->id]);
});

it('prompts once per duplicate series group', function () {
    $user = User::factory()->create();
    actingAs($user);

    $parent = Playlist::factory()->for($user)->create();
    $childA = Playlist::factory()->for($user)->create(['parent_id' => $parent->id]);
    $childB = Playlist::factory()->for($user)->create(['parent_id' => $parent->id]);

    $parentA = Series::factory()->for($user)->for($parent)->create(['source_series_id' => 1, 'name' => 'A']);
    $childASe = Series::factory()->for($user)->for($childA)->create(['source_series_id' => 1, 'name' => 'A']);
    $parentB = Series::factory()->for($user)->for($parent)->create(['source_series_id' => 2, 'name' => 'B']);
    $childBSe = Series::factory()->for($user)->for($childB)->create(['source_series_id' => 2, 'name' => 'B']);

    $records = new EloquentCollection([$childASe, $childBSe]);

    Queue::fake();
    $keys = [$parent->id . '-' . $childA->id, $parent->id . '-' . $childB->id];

    $custom = CustomPlaylist::factory()->for($user)->create();

    $addAction = getSeriesAddAction($records);
    $addAction->call([
        'playlist' => $custom->id,
        'category' => null,
        'source_playlists' => [
            $keys[0] => $parent->id,
            $keys[1] => $childB->id,
        ],
    ]);

    expect($custom->series()->pluck('id')->all())
        ->toEqualCanonicalizing([$parentA->id, $childBSe->id]);
});

