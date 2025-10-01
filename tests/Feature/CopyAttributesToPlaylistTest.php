<?php

use App\Jobs\CopyAttributesToPlaylist;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('can copy all attributes from source to target playlist', function () {
    // Create source playlist with channels
    $sourcePlaylist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $sourceChannel = Channel::factory()->create([
        'playlist_id' => $sourcePlaylist->id,
        'user_id' => $this->user->id,
        'source_id' => 'test-channel-1',
        'name' => 'Test Channel',
        'title' => 'Test Channel Title',
        'logo_internal' => 'https://example.com/logo.png',
        'stream_id' => 'stream123',
        'enabled' => true,
        'group' => 'Entertainment',
        'channel' => 100,
        'shift' => 2,
    ]);

    // Create target playlist with matching channel
    $targetPlaylist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $targetChannel = Channel::factory()->create([
        'playlist_id' => $targetPlaylist->id,
        'user_id' => $this->user->id,
        'source_id' => 'test-channel-1', // Same source_id for matching
        'name' => 'Different Name',
        'title' => 'Different Title',
        'logo' => null,
        'stream_id' => 'different-stream',
        'enabled' => false,
        'group' => 'Different Group',
        'channel' => 1,
        'shift' => 0,
        'name_custom' => null,
        'title_custom' => null,
        'stream_id_custom' => null,
    ]);

    // Run the job to copy all attributes
    $job = new CopyAttributesToPlaylist(
        source: $sourcePlaylist,
        targetId: $targetPlaylist->id,
        channelAttributes: [],
        allAttributes: true,
        overwrite: true // When copying all attributes, we should overwrite existing values
    );

    $job->handle();

    // Refresh the target channel
    $targetChannel->refresh();

    // Assert that custom fields were updated with source values
    expect($targetChannel->name_custom)->toBe('Test Channel');
    expect($targetChannel->title_custom)->toBe('Test Channel Title');
    expect($targetChannel->logo)->toBe('https://example.com/logo.png');
    expect($targetChannel->stream_id_custom)->toBe('stream123');
    expect($targetChannel->enabled)->toBe(true);
    expect($targetChannel->group)->toBe('Entertainment');
    expect($targetChannel->channel)->toBe(100);
    expect($targetChannel->shift)->toBe(2);
});

it('can copy specific attributes from source to target playlist', function () {
    // Create source playlist with channels
    $sourcePlaylist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $sourceChannel = Channel::factory()->create([
        'playlist_id' => $sourcePlaylist->id,
        'user_id' => $this->user->id,
        'source_id' => 'test-channel-2',
        'name' => 'Source Name',
        'title' => 'Source Title',
        'logo_internal' => 'https://example.com/source-logo.png',
        'stream_id' => 'source-stream',
        'enabled' => true,
    ]);

    // Create target playlist with matching channel
    $targetPlaylist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $targetChannel = Channel::factory()->create([
        'playlist_id' => $targetPlaylist->id,
        'user_id' => $this->user->id,
        'source_id' => 'test-channel-2',
        'name' => 'Target Name',
        'title' => 'Target Title',
        'logo' => null,
        'stream_id' => 'target-stream',
        'enabled' => false,
        'name_custom' => null,
        'title_custom' => null,
        'stream_id_custom' => null,
    ]);

    // Run the job to copy only name and logo
    $job = new CopyAttributesToPlaylist(
        source: $sourcePlaylist,
        targetId: $targetPlaylist->id,
        channelAttributes: ['name', 'logo'],
        allAttributes: false,
        overwrite: false
    );

    $job->handle();

    // Refresh the target channel
    $targetChannel->refresh();

    // Assert that only selected attributes were copied
    expect($targetChannel->name_custom)->toBe('Source Name');
    expect($targetChannel->logo)->toBe('https://example.com/source-logo.png');

    // These should remain unchanged since they weren't selected
    expect($targetChannel->title_custom)->toBeNull();
    expect($targetChannel->stream_id_custom)->toBeNull();
    expect($targetChannel->enabled)->toBe(false);
});

it('respects overwrite flag when copying attributes', function () {
    // Create source playlist
    $sourcePlaylist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $sourceChannel = Channel::factory()->create([
        'playlist_id' => $sourcePlaylist->id,
        'user_id' => $this->user->id,
        'source_id' => 'test-channel-3-overwrite',
        'name' => 'New Name',
        'title' => 'New Title',
    ]);

    // Create target playlist with existing custom values
    $targetPlaylist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $targetChannel = Channel::factory()->create([
        'playlist_id' => $targetPlaylist->id,
        'user_id' => $this->user->id,
        'source_id' => 'test-channel-3-overwrite',
        'name' => 'Target Name',
        'title' => 'Target Title',
        'name_custom' => 'Existing Custom Name',
        'title_custom' => null, // This should be updated since it's null
    ]);

    // Run the job with overwrite = false
    $job = new CopyAttributesToPlaylist(
        source: $sourcePlaylist,
        targetId: $targetPlaylist->id,
        channelAttributes: ['name', 'title'],
        allAttributes: false,
        overwrite: false
    );

    $job->handle();

    $targetChannel->refresh();

    // Existing custom value should not be overwritten when overwrite = false
    expect($targetChannel->name_custom)->toBe('Existing Custom Name');
    // Null value should be updated
    expect($targetChannel->title_custom)->toBe('New Title');

    // Now test with overwrite = true
    $job = new CopyAttributesToPlaylist(
        source: $sourcePlaylist,
        targetId: $targetPlaylist->id,
        channelAttributes: ['name', 'title'],
        allAttributes: false,
        overwrite: true
    );

    $job->handle();

    $targetChannel->refresh();

    // Now the existing value should be overwritten
    expect($targetChannel->name_custom)->toBe('New Name');
    expect($targetChannel->title_custom)->toBe('New Title');
});

it('can match channels by name and title when source_id does not match', function () {
    // Create source playlist
    $sourcePlaylist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $sourceChannel = Channel::factory()->create([
        'playlist_id' => $sourcePlaylist->id,
        'user_id' => $this->user->id,
        'source_id' => 'source-channel-id',
        'name' => 'Matching Channel Name',
        'title' => 'Matching Channel Title',
        'logo_internal' => 'https://example.com/fallback-logo.png',
    ]);

    // Create target playlist with different source_id but same name/title
    $targetPlaylist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $targetChannel = Channel::factory()->create([
        'playlist_id' => $targetPlaylist->id,
        'user_id' => $this->user->id,
        'source_id' => 'different-channel-id',
        'name' => 'Matching Channel Name',
        'title' => 'Matching Channel Title',
        'logo' => null,
    ]);

    // Run the job
    $job = new CopyAttributesToPlaylist(
        source: $sourcePlaylist,
        targetId: $targetPlaylist->id,
        channelAttributes: ['logo'],
        allAttributes: false,
        overwrite: false
    );

    $job->handle();

    $targetChannel->refresh();

    // Should match by name/title and copy the logo
    expect($targetChannel->logo)->toBe('https://example.com/fallback-logo.png');
});

it('handles cases where no matching channels are found', function () {
    // Create source playlist
    $sourcePlaylist = Playlist::factory()->create(['user_id' => $this->user->id]);
    Channel::factory()->create([
        'playlist_id' => $sourcePlaylist->id,
        'user_id' => $this->user->id,
        'source_id' => 'source-only-channel',
        'name' => 'Source Only Channel',
        'title' => 'Source Only Title',
    ]);

    // Create target playlist with completely different channels
    $targetPlaylist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $targetChannel = Channel::factory()->create([
        'playlist_id' => $targetPlaylist->id,
        'user_id' => $this->user->id,
        'source_id' => 'target-only-channel',
        'name' => 'Target Only Channel',
        'title' => 'Target Only Title',
        'name_custom' => null,
    ]);

    // Run the job
    $job = new CopyAttributesToPlaylist(
        source: $sourcePlaylist,
        targetId: $targetPlaylist->id,
        channelAttributes: ['name'],
        allAttributes: false,
        overwrite: false
    );

    $job->handle();

    $targetChannel->refresh();

    // Should remain unchanged since no matching channel was found
    expect($targetChannel->name_custom)->toBeNull();
});
