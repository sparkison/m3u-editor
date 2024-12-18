<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use App\Models\Playlist;
use App\Models\User;

class PlaylistFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Playlist::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'url' => $this->faker->url(),
            'status' => $this->faker->randomElement(["pending","processing","completed","failed"]),
            'prefix' => $this->faker->word(),
            'channels' => $this->faker->randomNumber(),
            'synced' => $this->faker->dateTime(),
            'errors' => $this->faker->text(),
            'user_id' => User::factory(),
        ];
    }
}
