<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use App\Models\Playlist;
use App\Models\PlaylistSyncStatus;
use App\Models\PlaylistSyncStatusLog;
use App\Models\User;

class PlaylistSyncStatusLogFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = PlaylistSyncStatusLog::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'user_id' => User::factory(),
            'playlist_id' => Playlist::factory(),
            'playlist_sync_status_id' => PlaylistSyncStatus::factory(),
            'type' => fake()->randomElement(["group","channel","unknown"]),
            'status' => fake()->randomElement(["added","removed","unknown"]),
            'meta' => '{}',
        ];
    }
}
