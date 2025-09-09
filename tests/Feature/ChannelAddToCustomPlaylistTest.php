<?php

use App\Filament\Resources\ChannelResource;
use App\Models\CustomPlaylist;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

function getChannelAddAction(EloquentCollection $records)
{
    $bulkActions = ChannelResource::getTableBulkActions();
    $bulkActionGroup = $bulkActions[0];
    $addAction = collect($bulkActionGroup->getActions())->first(fn($action) => $action->getName() === 'add');
    $addAction->records($records);
    return $addAction;
}

it('adds channels without source selector when no duplicates exist', function () {
    $user = User::factory()->create();
    actingAs($user);

    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($user)->create([
        'playlist_id' => $playlist->id,
        'source_id' => 1,
        'title' => 'A',
    ]);

    $custom = CustomPlaylist::factory()->for($user)->create();

    $records = new EloquentCollection([$channel]);

    Queue::fake();

    $addAction = getChannelAddAction($records);
    $addAction->call([
        'playlist' => $custom->id,
        'category' => null,
    ]);

    expect($custom->channels()->pluck('id'))->toContain($channel->id);
});

it('requires source playlist for duplicates and applies overrides', function () {
    $user = User::factory()->create();
    actingAs($user);

    $parent = Playlist::factory()->for($user)->create();
    $child  = Playlist::factory()->for($user)->create(['parent_id' => $parent->id]);

    $parentA = Channel::factory()->for($user)->create(['playlist_id' => $parent->id, 'source_id' => 1, 'title' => 'A']);
    $childA  = Channel::factory()->for($user)->create(['playlist_id' => $child->id, 'source_id' => 1, 'title' => 'A']);
    $parentB = Channel::factory()->for($user)->create(['playlist_id' => $parent->id, 'source_id' => 2, 'title' => 'B']);
    $childB  = Channel::factory()->for($user)->create(['playlist_id' => $child->id, 'source_id' => 2, 'title' => 'B']);

    $records = new EloquentCollection([$childA, $childB]);

    Queue::fake();
    $pairKey = $parent->id . '-' . $child->id;

    $custom = CustomPlaylist::factory()->for($user)->create();

    $addAction = getChannelAddAction($records);
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

    expect($custom->channels()->pluck('id')->all())
        ->toEqualCanonicalizing([$parentA->id, $childB->id]);
});

it('prompts once per duplicate group', function () {
    $user = User::factory()->create();
    actingAs($user);

    $parent = Playlist::factory()->for($user)->create();
    $childA = Playlist::factory()->for($user)->create(['parent_id' => $parent->id]);
    $childB = Playlist::factory()->for($user)->create(['parent_id' => $parent->id]);

    $parentA = Channel::factory()->for($user)->create(['playlist_id' => $parent->id, 'source_id' => 1, 'title' => 'A']);
    $childACh = Channel::factory()->for($user)->create(['playlist_id' => $childA->id, 'source_id' => 1, 'title' => 'A']);
    $parentB = Channel::factory()->for($user)->create(['playlist_id' => $parent->id, 'source_id' => 2, 'title' => 'B']);
    $childBCh = Channel::factory()->for($user)->create(['playlist_id' => $childB->id, 'source_id' => 2, 'title' => 'B']);

    $records = new EloquentCollection([$childACh, $childBCh]);

    Queue::fake();
    $keys = [$parent->id . '-' . $childA->id, $parent->id . '-' . $childB->id];

    $custom = CustomPlaylist::factory()->for($user)->create();

    $addAction = getChannelAddAction($records);
    $addAction->call([
        'playlist' => $custom->id,
        'category' => null,
        'source_playlists' => [
            $keys[0] => $parent->id,
            $keys[1] => $childB->id,
        ],
    ]);

    expect($custom->channels()->pluck('id')->all())
        ->toEqualCanonicalizing([$parentA->id, $childBCh->id]);
});

