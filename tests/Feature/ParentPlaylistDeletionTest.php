<?php

use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('converts child playlists to normal playlists when parent is deleted', function () {
    $user = User::factory()->create();
    actingAs($user);

    $parent = Playlist::factory()->for($user)->create();
    $childOne = Playlist::factory()->for($user)->create(['parent_id' => $parent->id]);
    $childTwo = Playlist::factory()->for($user)->create(['parent_id' => $parent->id]);

    $parent->delete();

    $childOne->refresh();
    $childTwo->refresh();

    expect($childOne->parent_id)->toBeNull();
    expect($childTwo->parent_id)->toBeNull();
    expect(Playlist::find($childOne->id))->not->toBeNull();
    expect(Playlist::find($childTwo->id))->not->toBeNull();
});
