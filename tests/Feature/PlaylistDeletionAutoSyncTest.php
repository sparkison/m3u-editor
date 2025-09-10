<?php

use App\Models\Playlist;
use App\Models\User;

it('restores auto sync for child playlists when parent deleted', function () {
    $user = User::factory()->create();
    $parent = Playlist::factory()->for($user)->create();
    $child = Playlist::factory()->for($user)->create([
        'parent_id' => $parent->id,
    ]);

    expect($child->auto_sync)->toBeFalse();

    $parent->delete();

    $child->refresh();

    expect($child->parent_id)->toBeNull();
    expect((bool) $child->auto_sync)->toBeTrue();
});

it('command restores auto sync for orphaned playlists', function () {
    $user = User::factory()->create();
    $parent = Playlist::factory()->for($user)->create();
    $child = Playlist::factory()->for($user)->create([
        'parent_id' => $parent->id,
    ]);

    // Simulate an orphaned playlist left with auto_sync disabled
    $child->parent_id = null;
    $child->save();

    expect($child->auto_sync)->toBeFalse();

    $this->artisan('app:fix-orphaned-playlists')->assertExitCode(0);

    $child->refresh();

    expect((bool) $child->auto_sync)->toBeTrue();
});
