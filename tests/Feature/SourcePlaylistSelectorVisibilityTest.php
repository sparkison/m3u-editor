<?php

use App\Filament\BulkActions\HandlesSourcePlaylist;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Filament\Forms;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

function makeFormHandler() {
    return new class {
        use HandlesSourcePlaylist {
            buildSourcePlaylistForm as public;
        }
    };
}

it('hides the source playlist selector when no duplicates exist', function () {
    $handler = makeFormHandler();

    $user = User::factory()->create();
    actingAs($user);

    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($user)->create([
        'playlist_id' => $playlist->id,
        'source_id'   => 1,
        'title'       => 'Channel 1',
    ]);

    $records = collect([$channel]);

    $form = $handler::buildSourcePlaylistForm($records, 'channels', 'source_id', 'channel');

    expect($form)->toBeEmpty();
});

it('renders one required selector for a single parent-child duplicate pair', function () {
    $handler = makeFormHandler();

    $user = User::factory()->create();
    actingAs($user);

    $parent = Playlist::factory()->for($user)->create();
    $child  = Playlist::factory()->for($user)->create(['parent_id' => $parent->id]);

    Channel::factory()->for($user)->create(['playlist_id' => $parent->id, 'source_id' => 1, 'title' => 'A']);
    $childChannel = Channel::factory()->for($user)->create(['playlist_id' => $child->id, 'source_id' => 1, 'title' => 'A']);

    $form = $handler::buildSourcePlaylistForm(collect([$childChannel]), 'channels', 'source_id', 'channel');

    expect($form)->toHaveCount(1);

    $select = $form[0]->getChildComponents()[0];

    expect($select)->toBeInstanceOf(Forms\Components\Select::class);
    expect($select->isRequired())->toBeTrue();
});

it('renders one selector per duplicated parent-child group', function () {
    $handler = makeFormHandler();

    $user = User::factory()->create();
    actingAs($user);

    $parent = Playlist::factory()->for($user)->create();
    $childA = Playlist::factory()->for($user)->create(['parent_id' => $parent->id]);
    $childB = Playlist::factory()->for($user)->create(['parent_id' => $parent->id]);

    Channel::factory()->for($user)->create(['playlist_id' => $parent->id, 'source_id' => 1, 'title' => 'A']);
    $childChannelA = Channel::factory()->for($user)->create(['playlist_id' => $childA->id, 'source_id' => 1, 'title' => 'A']);
    $childChannelB = Channel::factory()->for($user)->create(['playlist_id' => $childB->id, 'source_id' => 1, 'title' => 'A']);

    $form = $handler::buildSourcePlaylistForm(collect([$childChannelA, $childChannelB]), 'channels', 'source_id', 'channel');

    expect($form)->toHaveCount(2);

    foreach ($form as $fieldset) {
        $select = $fieldset->getChildComponents()[0];
        expect($select)->toBeInstanceOf(Forms\Components\Select::class);
        expect($select->isRequired())->toBeTrue();
    }
});


