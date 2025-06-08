<?php

namespace Tests\Feature;

use App\Models\Category; // Import Category
use App\Models\Channel;  // Import Channel
use App\Models\Group;    // Import Group
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\Series;   // Import Series
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class XtreamApiControllerTest extends TestCase
{
    use RefreshDatabase;

    // Standard setup for authenticated requests to panel action
    private function setupAuthenticatedPanelRequest(User &$user = null, Playlist &$playlist = null, array $playlistAuthCredentials = [])
    {
        $user = $user ?? User::factory()->create();
        $playlist = $playlist ?? Playlist::factory()->for($user)->create();

        $authUsername = $playlistAuthCredentials['username'] ?? 'testuser_panel';
        $authPassword = $playlistAuthCredentials['password'] ?? 'testpass_panel';

        if (!PlaylistAuth::where('playlist_id', $playlist->id)->exists()) {
            PlaylistAuth::factory()->for($playlist)->create([
                'username' => $authUsername,
                'password' => $authPassword,
                'is_enabled' => true,
            ]);
        }


        return $this->getJson(route('playlist.xtream.api', [
            'uuid' => $playlist->uuid,
            'action' => 'panel',
            'username' => $authUsername,
            'password' => $authPassword,
        ]));
    }


    public function test_panel_action_with_valid_playlist_auth_returns_correct_structure(): void
    {
        $response = $this->setupAuthenticatedPanelRequest();

        $response->assertOk();
        $response->assertJsonStructure([
            'user_info', 'server_info', 'available_channels', 'series', 'categories',
        ]);
        $response->assertJsonStructure([
            'user_info' => [
                'username', 'password', 'message', 'auth', 'status',
                'exp_date', 'is_trial', 'active_cons', 'created_at',
                'max_connections', 'allowed_output_formats',
            ],
        ]);
        $response->assertJsonPath('user_info.status', 'Active');
        $response->assertJsonStructure([
            'server_info' => [
                'url', 'port', 'https_port', 'rtmp_port', 'server_protocol',
                'timezone', 'server_software', 'timestamp_now', 'time_now',
            ],
        ]);
        $response->assertJsonPath('server_info.server_software', 'MediaFlow Xtream API');
    }

    public function test_panel_action_with_invalid_playlist_auth_returns_unauthorized(): void
    {
        $user = User::factory()->create();
        $playlist = Playlist::factory()->for($user)->create();
        PlaylistAuth::factory()->for($playlist)->create([ // Valid auth created
            'username' => 'correct_user',
            'password' => 'correct_password',
            'is_enabled' => true,
        ]);

        $response = $this->getJson(route('playlist.xtream.api', [
            'uuid' => $playlist->uuid,
            'action' => 'panel',
            'username' => 'correct_user',
            'password' => 'incorrect_password', // Using incorrect password
        ]));

        $response->assertStatus(401);
        $response->assertJson(['error' => 'Unauthorized']);
    }

    public function test_panel_action_with_m3ue_user_and_correct_password_returns_success(): void
    {
        $plainPassword = 'password123';
        $user = User::factory()->create(['password' => Hash::make($plainPassword)]);
        $playlist = Playlist::factory()->for($user)->create();
        PlaylistAuth::factory()->for($playlist)->create(['is_enabled' => false]); // Ensure PlaylistAuth not used

        $response = $this->getJson(route('playlist.xtream.api', [
            'uuid' => $playlist->uuid,
            'action' => 'panel',
            'username' => 'm3ue',
            'password' => $plainPassword,
        ]));

        $response->assertOk();
        $response->assertJsonPath('user_info.username', 'm3ue');
        $response->assertJsonStructure(['user_info', 'server_info', 'available_channels', 'series', 'categories']);
    }

    public function test_panel_action_with_m3ue_user_and_incorrect_password_returns_unauthorized(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct_password')]);
        $playlist = Playlist::factory()->for($user)->create();
        PlaylistAuth::factory()->for($playlist)->create(['is_enabled' => false]);

        $response = $this->getJson(route('playlist.xtream.api', [
            'uuid' => $playlist->uuid,
            'action' => 'panel',
            'username' => 'm3ue',
            'password' => 'incorrect_password',
        ]));

        $response->assertStatus(401);
        $response->assertJson(['error' => 'Unauthorized']);
    }

    public function test_panel_action_with_non_existent_playlist_uuid_returns_not_found(): void
    {
        $response = $this->getJson(route('playlist.xtream.api', [
            'uuid' => Str::uuid()->toString(), 'action' => 'panel', 'username' => 'any', 'password' => 'any',
        ]));
        $response->assertStatus(404);
        $response->assertJson(['error' => 'Playlist not found']);
    }

    public function test_panel_action_with_missing_credentials_returns_unauthorized(): void
    {
        $playlist = Playlist::factory()->create(); // User automatically created by Playlist factory if not specified

        $responseMissingUser = $this->getJson(route('playlist.xtream.api', [
            'uuid' => $playlist->uuid, 'action' => 'panel', 'password' => 'test',
        ]));
        $responseMissingUser->assertStatus(401)->assertJson(['error' => 'Unauthorized - Missing credentials']);

        $responseMissingPass = $this->getJson(route('playlist.xtream.api', [
            'uuid' => $playlist->uuid, 'action' => 'panel', 'username' => 'test',
        ]));
        $responseMissingPass->assertStatus(401)->assertJson(['error' => 'Unauthorized - Missing credentials']);
    }

    public function test_panel_action_contains_playlist_channels(): void
    {
        $user = null; $playlist = null; // Passed by reference to setup
        $group = Group::factory()->create(['name' => 'Test Group']); // ID will be auto-generated

        // This will be passed by reference and populated by setupAuthenticatedPanelRequest
        $this->setupAuthenticatedPanelRequest($user, $playlist);

        $channel1 = Channel::factory()->for($playlist)->for($group)->create(['title' => 'Channel 1', 'enabled' => true]);
        Channel::factory()->for($playlist)->for($group)->create(['title' => 'Channel 2', 'enabled' => true]);
        Channel::factory()->for($playlist)->create(['title' => 'Channel Disabled', 'enabled' => false]); // Should not appear

        // Re-fetch after adding channels
        $response = $this->setupAuthenticatedPanelRequest($user, $playlist);

        $response->assertOk();
        $response->assertJsonCount(2, 'available_channels'); // Only enabled channels
        $response->assertJsonPath('available_channels.0.name', 'Channel 1');
        $response->assertJsonPath('available_channels.0.stream_id', $channel1->id); // stream_id is int
        $response->assertJsonPath('available_channels.0.category_id', (string)$group->id); // category_id is string

        $response->assertJsonFragment([ // Check category presence
            'category_id' => (string)$group->id,
            'category_name' => 'Test Group',
        ]);
    }

    public function test_panel_action_contains_playlist_series(): void
    {
        $user = null; $playlist = null;
        $seriesCategory = Category::factory()->create(['name' => 'Test Series Category']);

        $this->setupAuthenticatedPanelRequest($user, $playlist);

        $seriesA = Series::factory()->for($playlist)->for($seriesCategory)->create(['name' => 'Series A', 'enabled' => true]);
        Series::factory()->for($playlist)->for($seriesCategory)->create(['name' => 'Series B', 'enabled' => true]);
        Series::factory()->for($playlist)->create(['name' => 'Series Disabled', 'enabled' => false]); // Should not appear

        // Re-fetch after adding series
        $response = $this->setupAuthenticatedPanelRequest($user, $playlist);

        $response->assertOk();
        $response->assertJsonCount(2, 'series'); // Only enabled series
        $response->assertJsonPath('series.0.name', 'Series A');
        $response->assertJsonPath('series.0.series_id', $seriesA->id); // series_id is int
        $response->assertJsonPath('series.0.category_id', (string)$seriesCategory->id); // category_id is string

        $response->assertJsonFragment([
            'category_id' => (string)$seriesCategory->id,
            'category_name' => 'Test Series Category',
        ]);
    }

    public function test_panel_action_contains_mixed_categories(): void
    {
        $user = null; $playlist = null;
        $liveGroup = Group::factory()->create(['name' => 'Live Group']);
        $vodCategory = Category::factory()->create(['name' => 'VOD Category']);

        $this->setupAuthenticatedPanelRequest($user, $playlist);

        Channel::factory()->for($playlist)->for($liveGroup)->create(['enabled' => true]);
        Series::factory()->for($playlist)->for($vodCategory)->create(['enabled' => true]);

        $response = $this->setupAuthenticatedPanelRequest($user, $playlist);

        $response->assertOk();
        $response->assertJsonFragment(['category_name' => 'Live Group', 'category_id' => (string)$liveGroup->id]);
        $response->assertJsonFragment(['category_name' => 'VOD Category', 'category_id' => (string)$vodCategory->id]);
    }
}
