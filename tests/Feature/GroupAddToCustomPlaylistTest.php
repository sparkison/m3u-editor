<?php

use App\Models\{User, Playlist, Group, Channel, CustomPlaylist};

it('adds all channels from a group to a custom playlist', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $group = Group::factory()->for($user)->for($playlist)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->for($group)->create([
        'group' => $group->name,
    ]);
    $custom = CustomPlaylist::factory()->for($user)->create();

    // mimic the GroupResource bulk "Add to Custom Playlist" behaviour
    $custom->channels()->syncWithoutDetaching($group->channels()->pluck('id'));

    expect($custom->channels()->whereKey($channel->id)->exists())->toBeTrue();
});
