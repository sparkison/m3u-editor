<?php

use App\Models\Playlist;
use App\Models\PlaylistProfile;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->create([
        'user_id' => $this->user->id,
        'profiles_enabled' => true,
        'xtream_config' => [
            'server' => 'http://example.com',
            'username' => 'primary_user',
            'password' => 'primary_pass',
            'output' => 'ts',
        ],
    ]);
});

it('can create a playlist profile', function () {
    $profile = PlaylistProfile::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'name' => 'Test Profile',
        'username' => 'test_user',
        'password' => 'test_pass',
    ]);

    expect($profile)
        ->toBeInstanceOf(PlaylistProfile::class)
        ->name->toBe('Test Profile')
        ->username->toBe('test_user')
        ->password->toBe('test_pass');
});

it('belongs to a playlist', function () {
    $profile = PlaylistProfile::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
    ]);

    expect($profile->playlist)
        ->toBeInstanceOf(Playlist::class)
        ->id->toBe($this->playlist->id);
});

it('belongs to a user', function () {
    $profile = PlaylistProfile::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
    ]);

    expect($profile->user)
        ->toBeInstanceOf(User::class)
        ->id->toBe($this->user->id);
});

it('generates xtream config from playlist base and profile credentials', function () {
    $profile = PlaylistProfile::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'username' => 'profile_user',
        'password' => 'profile_pass',
    ]);

    $config = $profile->xtream_config;

    expect($config)
        ->toBeArray()
        ->toHaveKeys(['server', 'username', 'password', 'output'])
        ->and($config['server'])->toBe('http://example.com')
        ->and($config['username'])->toBe('profile_user')
        ->and($config['password'])->toBe('profile_pass')
        ->and($config['output'])->toBe('ts');
});

it('returns null xtream config when playlist has no config', function () {
    $playlistWithoutXtream = Playlist::factory()->create([
        'user_id' => $this->user->id,
        'xtream_config' => null,
    ]);

    $profile = PlaylistProfile::factory()->create([
        'playlist_id' => $playlistWithoutXtream->id,
        'user_id' => $this->user->id,
    ]);

    expect($profile->xtream_config)->toBeNull();
});

it('can identify primary profile', function () {
    $primaryProfile = PlaylistProfile::factory()->primary()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
    ]);

    expect($primaryProfile)
        ->is_primary->toBeTrue()
        ->priority->toBe(0);
});

it('scopes to only enabled profiles', function () {
    PlaylistProfile::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'enabled' => true,
    ]);

    PlaylistProfile::factory()->disabled()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
    ]);

    expect(PlaylistProfile::enabled()->count())->toBe(1);
});

it('orders profiles by selection priority', function () {
    PlaylistProfile::factory()->withPriority(10)->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'name' => 'Low Priority',
    ]);

    PlaylistProfile::factory()->withPriority(1)->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'name' => 'High Priority',
    ]);

    $profiles = PlaylistProfile::orderBySelection()->get();

    expect($profiles->first()->name)->toBe('High Priority');
    expect($profiles->last()->name)->toBe('Low Priority');
});

it('calculates current connections from provider info', function () {
    $profile = PlaylistProfile::factory()->withProviderInfo(
        activeConnections: 3,
        maxConnections: 5
    )->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
    ]);

    expect($profile->current_connections)->toBe(3);
});

it('calculates provider max connections from provider info', function () {
    $profile = PlaylistProfile::factory()->withProviderInfo(
        activeConnections: 3,
        maxConnections: 5
    )->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
    ]);

    expect($profile->provider_max_connections)->toBe(5);
});

it('uses user-defined max streams when set', function () {
    $profile = PlaylistProfile::factory()
        ->withProviderInfo(activeConnections: 0, maxConnections: 10)
        ->withMaxStreams(5)
        ->create([
            'playlist_id' => $this->playlist->id,
            'user_id' => $this->user->id,
        ]);

    expect($profile->effective_max_streams)->toBe(5);
});

it('uses provider max when user max is higher', function () {
    $profile = PlaylistProfile::factory()
        ->withProviderInfo(activeConnections: 0, maxConnections: 3)
        ->withMaxStreams(10)
        ->create([
            'playlist_id' => $this->playlist->id,
            'user_id' => $this->user->id,
        ]);

    expect($profile->effective_max_streams)->toBe(3);
});

it('calculates available streams correctly', function () {
    $profile = PlaylistProfile::factory()
        ->withProviderInfo(activeConnections: 2, maxConnections: 5)
        ->create([
            'playlist_id' => $this->playlist->id,
            'user_id' => $this->user->id,
        ]);

    expect($profile->available_streams)->toBe(3);
});

it('reports no available streams when at capacity', function () {
    $profile = PlaylistProfile::factory()
        ->withProviderInfo(activeConnections: 5, maxConnections: 5)
        ->create([
            'playlist_id' => $this->playlist->id,
            'user_id' => $this->user->id,
        ]);

    expect($profile->available_streams)->toBe(0);
    expect($profile->hasCapacity())->toBeFalse();
});

it('reports has capacity when streams available', function () {
    $profile = PlaylistProfile::factory()
        ->withProviderInfo(activeConnections: 2, maxConnections: 5)
        ->create([
            'playlist_id' => $this->playlist->id,
            'user_id' => $this->user->id,
        ]);

    expect($profile->hasCapacity())->toBeTrue();
});

it('reports no capacity when disabled', function () {
    $profile = PlaylistProfile::factory()
        ->disabled()
        ->withProviderInfo(activeConnections: 0, maxConnections: 5)
        ->create([
            'playlist_id' => $this->playlist->id,
            'user_id' => $this->user->id,
        ]);

    expect($profile->hasCapacity())->toBeFalse();
});

it('can get primary profile for playlist', function () {
    PlaylistProfile::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'is_primary' => false,
    ]);

    $primary = PlaylistProfile::factory()->primary()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'name' => 'Primary Profile',
    ]);

    $found = PlaylistProfile::getPrimaryForPlaylist($this->playlist->id);

    expect($found)
        ->toBeInstanceOf(PlaylistProfile::class)
        ->id->toBe($primary->id)
        ->name->toBe('Primary Profile');
});

it('selects profile with capacity for streaming', function () {
    // Profile at capacity
    PlaylistProfile::factory()
        ->withPriority(1)
        ->withProviderInfo(activeConnections: 5, maxConnections: 5)
        ->create([
            'playlist_id' => $this->playlist->id,
            'user_id' => $this->user->id,
            'name' => 'Full Profile',
        ]);

    // Profile with capacity
    $available = PlaylistProfile::factory()
        ->withPriority(2)
        ->withProviderInfo(activeConnections: 1, maxConnections: 5)
        ->create([
            'playlist_id' => $this->playlist->id,
            'user_id' => $this->user->id,
            'name' => 'Available Profile',
        ]);

    $selected = PlaylistProfile::selectForStreaming($this->playlist->id);

    expect($selected)
        ->toBeInstanceOf(PlaylistProfile::class)
        ->id->toBe($available->id)
        ->name->toBe('Available Profile');
});

it('returns null when no profiles have capacity', function () {
    PlaylistProfile::factory()
        ->withProviderInfo(activeConnections: 5, maxConnections: 5)
        ->create([
            'playlist_id' => $this->playlist->id,
            'user_id' => $this->user->id,
        ]);

    $selected = PlaylistProfile::selectForStreaming($this->playlist->id);

    expect($selected)->toBeNull();
});

it('excludes specific profile when selecting for streaming', function () {
    $profile1 = PlaylistProfile::factory()
        ->withPriority(1)
        ->withProviderInfo(activeConnections: 0, maxConnections: 5)
        ->create([
            'playlist_id' => $this->playlist->id,
            'user_id' => $this->user->id,
            'name' => 'Profile 1',
        ]);

    $profile2 = PlaylistProfile::factory()
        ->withPriority(2)
        ->withProviderInfo(activeConnections: 0, maxConnections: 5)
        ->create([
            'playlist_id' => $this->playlist->id,
            'user_id' => $this->user->id,
            'name' => 'Profile 2',
        ]);

    // Exclude profile1, should select profile2
    $selected = PlaylistProfile::selectForStreaming($this->playlist->id, $profile1->id);

    expect($selected)
        ->toBeInstanceOf(PlaylistProfile::class)
        ->id->toBe($profile2->id)
        ->name->toBe('Profile 2');
});

it('playlist has profiles relationship', function () {
    PlaylistProfile::factory()->count(3)->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
    ]);

    expect($this->playlist->profiles)->toHaveCount(3);
});

it('playlist has enabled profiles relationship', function () {
    PlaylistProfile::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'enabled' => true,
    ]);

    PlaylistProfile::factory()->disabled()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
    ]);

    expect($this->playlist->enabledProfiles)->toHaveCount(1);
});
