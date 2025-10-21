<?php

use App\Enums\PlaylistSourceType;
use App\Enums\Status;
use App\Events\SyncCompleted;
use App\Jobs\ProcessEmbyVodSync;
use App\Jobs\ProcessEmbySeriesSync;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create a test user
    $this->user = User::factory()->create();
});

it('dispatches Emby VOD resync after playlist sync when configured', function () {
    Queue::fake();

    // Create a playlist with Emby VOD configuration
    $playlist = Playlist::factory()->create([
        'user_id' => $this->user->id,
        'source_type' => PlaylistSourceType::Emby,
        'status' => Status::Completed,
        'emby_config' => [
            'vod' => [
                'library_id' => 'test-library-123',
                'library_name' => 'Movies',
                'use_direct_path' => false,
                'auto_enable' => true,
                'import_groups_from_genres' => null,
            ],
        ],
    ]);

    // Trigger sync completed event from main playlist sync
    event(new SyncCompleted($playlist, 'playlist'));

    // Assert that the Emby VOD sync job was dispatched
    Queue::assertPushed(ProcessEmbyVodSync::class, function ($job) use ($playlist) {
        return $job->playlist->id === $playlist->id
            && $job->libraryId === 'test-library-123'
            && $job->libraryName === 'Movies';
    });
});

it('dispatches Emby Series resync after playlist sync when configured', function () {
    Queue::fake();

    // Create a playlist with Emby Series configuration
    $playlist = Playlist::factory()->create([
        'user_id' => $this->user->id,
        'source_type' => PlaylistSourceType::Emby,
        'status' => Status::Completed,
        'emby_config' => [
            'series' => [
                'library_id' => 'test-library-456',
                'library_name' => 'TV Shows',
                'use_direct_path' => false,
                'auto_enable' => true,
                'import_categories_from_genres' => null,
            ],
        ],
    ]);

    // Trigger sync completed event from main playlist sync
    event(new SyncCompleted($playlist, 'playlist'));

    // Assert that the Emby Series sync job was dispatched
    Queue::assertPushed(ProcessEmbySeriesSync::class, function ($job) use ($playlist) {
        return $job->playlist->id === $playlist->id
            && $job->libraryId === 'test-library-456'
            && $job->libraryName === 'TV Shows';
    });
});

it('dispatches both VOD and Series resyncs when both are configured', function () {
    Queue::fake();

    // Create a playlist with both Emby VOD and Series configuration
    $playlist = Playlist::factory()->create([
        'user_id' => $this->user->id,
        'source_type' => PlaylistSourceType::Emby,
        'status' => Status::Completed,
        'emby_config' => [
            'vod' => [
                'library_id' => 'vod-library-123',
                'library_name' => 'Movies',
                'use_direct_path' => false,
                'auto_enable' => true,
                'import_groups_from_genres' => null,
            ],
            'series' => [
                'library_id' => 'series-library-456',
                'library_name' => 'TV Shows',
                'use_direct_path' => false,
                'auto_enable' => true,
                'import_categories_from_genres' => null,
            ],
        ],
    ]);

    // Trigger sync completed event from main playlist sync
    event(new SyncCompleted($playlist, 'playlist'));

    // Assert that both jobs were dispatched
    Queue::assertPushed(ProcessEmbyVodSync::class);
    Queue::assertPushed(ProcessEmbySeriesSync::class);
});

it('does not dispatch Emby resync for non-Emby playlists', function () {
    Queue::fake();

    // Create a regular playlist (not Emby)
    $playlist = Playlist::factory()->create([
        'user_id' => $this->user->id,
        'source_type' => PlaylistSourceType::M3u,
        'status' => Status::Completed,
    ]);

    // Trigger sync completed event from main playlist sync
    event(new SyncCompleted($playlist, 'playlist'));

    // Assert that no Emby jobs were dispatched
    Queue::assertNotPushed(ProcessEmbyVodSync::class);
    Queue::assertNotPushed(ProcessEmbySeriesSync::class);
});

it('does not dispatch Emby resync when playlist sync fails', function () {
    Queue::fake();

    // Create an Emby playlist with failed status
    $playlist = Playlist::factory()->create([
        'user_id' => $this->user->id,
        'source_type' => PlaylistSourceType::Emby,
        'status' => Status::Failed,
        'emby_config' => [
            'vod' => [
                'library_id' => 'test-library-123',
                'library_name' => 'Movies',
                'use_direct_path' => false,
                'auto_enable' => true,
                'import_groups_from_genres' => null,
            ],
        ],
    ]);

    // Trigger sync completed event from main playlist sync
    event(new SyncCompleted($playlist, 'playlist'));

    // Assert that no Emby jobs were dispatched
    Queue::assertNotPushed(ProcessEmbyVodSync::class);
    Queue::assertNotPushed(ProcessEmbySeriesSync::class);
});

it('does not dispatch Emby resync when emby_config is empty', function () {
    Queue::fake();

    // Create an Emby playlist without config
    $playlist = Playlist::factory()->create([
        'user_id' => $this->user->id,
        'source_type' => PlaylistSourceType::Emby,
        'status' => Status::Completed,
        'emby_config' => null,
    ]);

    // Trigger sync completed event from main playlist sync
    event(new SyncCompleted($playlist, 'playlist'));

    // Assert that no Emby jobs were dispatched
    Queue::assertNotPushed(ProcessEmbyVodSync::class);
    Queue::assertNotPushed(ProcessEmbySeriesSync::class);
});

it('does not dispatch Emby resync when sync source is emby_vod (prevents infinite loop)', function () {
    Queue::fake();

    // Create an Emby playlist with VOD config
    $playlist = Playlist::factory()->create([
        'user_id' => $this->user->id,
        'source_type' => PlaylistSourceType::Emby,
        'status' => Status::Completed,
        'emby_config' => [
            'vod' => [
                'library_id' => 'test-library-123',
                'library_name' => 'Movies',
                'use_direct_path' => false,
                'auto_enable' => true,
                'import_groups_from_genres' => null,
            ],
        ],
    ]);

    // Trigger sync completed event from Emby VOD sync (not main playlist)
    event(new SyncCompleted($playlist, 'emby_vod'));

    // Assert that no new Emby jobs were dispatched (preventing infinite loop)
    Queue::assertNotPushed(ProcessEmbyVodSync::class);
    Queue::assertNotPushed(ProcessEmbySeriesSync::class);
});

it('does not dispatch Emby resync when sync source is emby_series (prevents infinite loop)', function () {
    Queue::fake();

    // Create an Emby playlist with Series config
    $playlist = Playlist::factory()->create([
        'user_id' => $this->user->id,
        'source_type' => PlaylistSourceType::Emby,
        'status' => Status::Completed,
        'emby_config' => [
            'series' => [
                'library_id' => 'test-library-456',
                'library_name' => 'TV Shows',
                'use_direct_path' => false,
                'auto_enable' => true,
                'import_categories_from_genres' => null,
            ],
        ],
    ]);

    // Trigger sync completed event from Emby Series sync (not main playlist)
    event(new SyncCompleted($playlist, 'emby_series'));

    // Assert that no new Emby jobs were dispatched (preventing infinite loop)
    Queue::assertNotPushed(ProcessEmbyVodSync::class);
    Queue::assertNotPushed(ProcessEmbySeriesSync::class);
});
