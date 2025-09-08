<?php

use App\Filament\BulkActions\HandlesSourcePlaylist;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function makeHandler() {
    return new class {
        use HandlesSourcePlaylist {
            getSourcePlaylistData as public;
            mapRecordsToSourcePlaylist as public;
        }
    };
}

it('applies bulk source selection when no overrides', function () {
    $handler = makeHandler();

    $user = User::factory()->create();
    actingAs($user);

    $parent = Playlist::factory()->for($user)->create();
    $child  = Playlist::factory()->for($user)->create(['parent_id' => $parent->id]);

    $parentChannel1 = Channel::factory()->for($user)->create(['playlist_id' => $parent->id, 'source_id' => 1, 'title' => 'A']);
    $childChannel1  = Channel::factory()->for($user)->create(['playlist_id' => $child->id, 'source_id' => 1, 'title' => 'A']);
    $parentChannel2 = Channel::factory()->for($user)->create(['playlist_id' => $parent->id, 'source_id' => 2, 'title' => 'B']);
    $childChannel2  = Channel::factory()->for($user)->create(['playlist_id' => $child->id, 'source_id' => 2, 'title' => 'B']);

    $records = collect([$childChannel1, $childChannel2]);

    $data = $handler::getSourcePlaylistData($records, 'channels', 'source_id');
    [$groups] = $data;
    $pairKey = $groups->keys()->first();

    $mapped = $handler::mapRecordsToSourcePlaylist(
        $records,
        ['source_playlists' => [$pairKey => $parent->id]],
        'channels',
        'source_id',
        Channel::class,
        $data
    );

    expect($mapped->pluck('id')->all())->toEqual([$parentChannel1->id, $parentChannel2->id]);
});

it('applies per-item overrides', function () {
    $handler = makeHandler();

    $user = User::factory()->create();
    actingAs($user);

    $parent = Playlist::factory()->for($user)->create();
    $child  = Playlist::factory()->for($user)->create(['parent_id' => $parent->id]);

    $parentChannel1 = Channel::factory()->for($user)->create(['playlist_id' => $parent->id, 'source_id' => 1, 'title' => 'A']);
    $childChannel1  = Channel::factory()->for($user)->create(['playlist_id' => $child->id, 'source_id' => 1, 'title' => 'A']);
    $parentChannel2 = Channel::factory()->for($user)->create(['playlist_id' => $parent->id, 'source_id' => 2, 'title' => 'B']);
    $childChannel2  = Channel::factory()->for($user)->create(['playlist_id' => $child->id, 'source_id' => 2, 'title' => 'B']);

    $records = collect([$childChannel1, $childChannel2]);

    $data = $handler::getSourcePlaylistData($records, 'channels', 'source_id');
    [$groups] = $data;
    $pairKey = $groups->keys()->first();

    $mapped = $handler::mapRecordsToSourcePlaylist(
        $records,
        [
            'source_playlists' => [$pairKey => $parent->id],
            'source_playlists_items' => [
                $pairKey => [
                    $childChannel2->id => $child->id,
                ],
            ],
        ],
        'channels',
        'source_id',
        Channel::class,
        $data
    );

    expect($mapped->pluck('id')->all())->toEqual([$parentChannel1->id, $childChannel2->id]);
});

it('maps records to correct groups when channel exists in multiple child playlists', function () {
    $handler = makeHandler();

    $user = User::factory()->create();
    actingAs($user);

    $parent = Playlist::factory()->for($user)->create();
    $childA = Playlist::factory()->for($user)->create(['parent_id' => $parent->id]);
    $childB = Playlist::factory()->for($user)->create(['parent_id' => $parent->id]);

    $parentChannel = Channel::factory()->for($user)->create(['playlist_id' => $parent->id, 'source_id' => 1, 'title' => 'A']);
    $childAChannel = Channel::factory()->for($user)->create(['playlist_id' => $childA->id, 'source_id' => 1, 'title' => 'A']);
    $childBChannel = Channel::factory()->for($user)->create(['playlist_id' => $childB->id, 'source_id' => 1, 'title' => 'A']);

    $records = collect([$childAChannel, $childBChannel]);

    $data = $handler::getSourcePlaylistData($records, 'channels', 'source_id');
    [$groups] = $data;

    $mapped = $handler::mapRecordsToSourcePlaylist(
        $records,
        [
            'source_playlists' => [
                $parent->id . '-' . $childA->id => $parent->id,
                $parent->id . '-' . $childB->id => $childB->id,
            ],
        ],
        'channels',
        'source_id',
        Channel::class,
        $data
    );

    expect($mapped->pluck('id')->all())->toEqual([$parentChannel->id, $childBChannel->id]);
});

it('fails validation when selections are missing', function () {
    $handler = makeHandler();

    $user = User::factory()->create();
    actingAs($user);

    $parent = Playlist::factory()->for($user)->create();
    $child  = Playlist::factory()->for($user)->create(['parent_id' => $parent->id]);

    $parentChannel1 = Channel::factory()->for($user)->create(['playlist_id' => $parent->id, 'source_id' => 1, 'title' => 'A']);
    $childChannel1  = Channel::factory()->for($user)->create(['playlist_id' => $child->id, 'source_id' => 1, 'title' => 'A']);
    $parentChannel2 = Channel::factory()->for($user)->create(['playlist_id' => $parent->id, 'source_id' => 2, 'title' => 'B']);
    $childChannel2  = Channel::factory()->for($user)->create(['playlist_id' => $child->id, 'source_id' => 2, 'title' => 'B']);

    $records = collect([$childChannel1, $childChannel2]);

    $data = $handler::getSourcePlaylistData($records, 'channels', 'source_id');
    [$groups] = $data;
    $pairKey = $groups->keys()->first();

    $handler::mapRecordsToSourcePlaylist(
        $records,
        [
            'source_playlists_items' => [
                $pairKey => [
                    $childChannel1->id => $parent->id,
                ],
            ],
        ],
        'channels',
        'source_id',
        Channel::class,
        $data
    );
})->throws(ValidationException::class);
