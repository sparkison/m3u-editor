<?php

use App\Filament\BulkActions\HandlesSourcePlaylist;
use App\Models\Channel;
use App\Models\Series;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Filament\Forms;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

dataset('mediaTypes', [
    'channels' => [Channel::class, 'channels', 'source_id', 'channel', []],
    'series'   => [Series::class, 'series', 'source_series_id', 'series', []],
    'vod'      => [Channel::class, 'channels', 'source_id', 'channel', ['is_vod' => true]],
]);

function makeFormHandler() {
    return new class {
        use HandlesSourcePlaylist {
            buildSourcePlaylistForm as public;
        }
    };
}

it('hides the source playlist selector when no duplicates exist', function (string $modelClass, string $relation, string $sourceKey, string $label, array $extra) {
    $handler = makeFormHandler();

    $user = User::factory()->create();
    actingAs($user);

    $playlist = Playlist::factory()->for($user)->create();
    $record = $modelClass::factory()->for($user)->create(array_merge($extra, [
        'playlist_id' => $playlist->id,
        $sourceKey    => 1,
    ]));

    $form = $handler::buildSourcePlaylistForm(collect([$record]), $relation, $sourceKey, $label);

    expect($form)->toBeEmpty();
})->with('mediaTypes');

it('renders one required selector for a single parent-child duplicate pair', function (string $modelClass, string $relation, string $sourceKey, string $label, array $extra) {
    $handler = makeFormHandler();

    $user = User::factory()->create();
    actingAs($user);

    $parent = Playlist::factory()->for($user)->create();
    $child  = Playlist::factory()->for($user)->create(['parent_id' => $parent->id]);

    $modelClass::factory()->for($user)->create(array_merge($extra, [
        'playlist_id' => $parent->id,
        $sourceKey    => 1,
    ]));

    $childRecord = $modelClass::factory()->for($user)->create(array_merge($extra, [
        'playlist_id' => $child->id,
        $sourceKey    => 1,
    ]));

    $form = $handler::buildSourcePlaylistForm(collect([$childRecord]), $relation, $sourceKey, $label);

    expect($form)->toHaveCount(1);

    $select = $form[0]->getChildComponents()[0];

    expect($select)->toBeInstanceOf(Forms\Components\Select::class);
    expect($select->isRequired())->toBeTrue();
})->with('mediaTypes');

it('renders selector when selecting the parent item of a duplicate pair', function (string $modelClass, string $relation, string $sourceKey, string $label, array $extra) {
    $handler = makeFormHandler();

    $user = User::factory()->create();
    actingAs($user);

    $parent = Playlist::factory()->for($user)->create();
    $child  = Playlist::factory()->for($user)->create(['parent_id' => $parent->id]);

    $parentRecord = $modelClass::factory()->for($user)->create(array_merge($extra, [
        'playlist_id' => $parent->id,
        $sourceKey    => 1,
    ]));
    $modelClass::factory()->for($user)->create(array_merge($extra, [
        'playlist_id' => $child->id,
        $sourceKey    => 1,
    ]));

    $form = $handler::buildSourcePlaylistForm(collect([$parentRecord]), $relation, $sourceKey, $label);

    expect($form)->toHaveCount(1);
    $select = $form[0]->getChildComponents()[0];
    expect($select)->toBeInstanceOf(Forms\Components\Select::class);
    expect($select->isRequired())->toBeTrue();
})->with('mediaTypes');

it('renders one selector per duplicated parent-child group', function (string $modelClass, string $relation, string $sourceKey, string $label, array $extra) {
    $handler = makeFormHandler();

    $user = User::factory()->create();
    actingAs($user);

    $parent = Playlist::factory()->for($user)->create();
    $childA = Playlist::factory()->for($user)->create(['parent_id' => $parent->id]);
    $childB = Playlist::factory()->for($user)->create(['parent_id' => $parent->id]);

    $modelClass::factory()->for($user)->create(array_merge($extra, [
        'playlist_id' => $parent->id,
        $sourceKey    => 1,
    ]));

    $childRecordA = $modelClass::factory()->for($user)->create(array_merge($extra, [
        'playlist_id' => $childA->id,
        $sourceKey    => 1,
    ]));
    $childRecordB = $modelClass::factory()->for($user)->create(array_merge($extra, [
        'playlist_id' => $childB->id,
        $sourceKey    => 1,
    ]));

    $form = $handler::buildSourcePlaylistForm(collect([$childRecordA, $childRecordB]), $relation, $sourceKey, $label);

    expect($form)->toHaveCount(2);

    foreach ($form as $fieldset) {
        $select = $fieldset->getChildComponents()[0];
        expect($select)->toBeInstanceOf(Forms\Components\Select::class);
        expect($select->isRequired())->toBeTrue();
    }
})->with('mediaTypes');