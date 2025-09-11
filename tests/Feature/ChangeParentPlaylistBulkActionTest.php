<?php

use App\Models\{User, Playlist, Channel, Series, CustomPlaylist};

it('changes parent playlist for selected channels', function () {
    $user = User::factory()->create();
    $playlistA = Playlist::factory()->for($user)->create();
    $playlistB = Playlist::factory()->for($user)->create();
    $channelA = Channel::factory()->for($user)->for($playlistA)->create(['source_id' => 1]);
    $channelB = Channel::factory()->for($user)->for($playlistB)->create(['source_id' => 1]);
    $custom = CustomPlaylist::factory()->for($user)->create();
    $custom->channels()->attach($channelA);

    $replacement = Channel::where('playlist_id', $playlistB->id)
        ->where('source_id', $channelA->source_id)
        ->first();

    $custom->channels()->detach($channelA->id);
    $custom->channels()->attach($replacement->id);

    expect($custom->channels()->whereKey($replacement->id)->exists())->toBeTrue();
    expect($custom->channels()->whereKey($channelA->id)->exists())->toBeFalse();
});

it('changes parent playlist for selected vod', function () {
    $user = User::factory()->create();
    $playlistA = Playlist::factory()->for($user)->create();
    $playlistB = Playlist::factory()->for($user)->create();
    $vodA = Channel::factory()->for($user)->for($playlistA)->create(['source_id' => 2, 'is_vod' => true]);
    $vodB = Channel::factory()->for($user)->for($playlistB)->create(['source_id' => 2, 'is_vod' => true]);
    $custom = CustomPlaylist::factory()->for($user)->create();
    $custom->channels()->attach($vodA);

    $replacement = Channel::where('playlist_id', $playlistB->id)
        ->where('source_id', $vodA->source_id)
        ->first();

    $custom->channels()->detach($vodA->id);
    $custom->channels()->attach($replacement->id);

    expect($custom->channels()->whereKey($replacement->id)->exists())->toBeTrue();
    expect($custom->channels()->whereKey($vodA->id)->exists())->toBeFalse();
});

it('changes parent playlist for selected series', function () {
    $user = User::factory()->create();
    $playlistA = Playlist::factory()->for($user)->create();
    $playlistB = Playlist::factory()->for($user)->create();
    $seriesA = Series::factory()->for($user)->for($playlistA)->create(['source_series_id' => 10]);
    $seriesB = Series::factory()->for($user)->for($playlistB)->create(['source_series_id' => 10]);
    $custom = CustomPlaylist::factory()->for($user)->create();
    $custom->series()->attach($seriesA);

    $replacement = Series::where('playlist_id', $playlistB->id)
        ->where('source_series_id', $seriesA->source_series_id)
        ->first();

    $custom->series()->detach($seriesA->id);
    $custom->series()->attach($replacement->id);

    expect($custom->series()->whereKey($replacement->id)->exists())->toBeTrue();
    expect($custom->series()->whereKey($seriesA->id)->exists())->toBeFalse();
});
