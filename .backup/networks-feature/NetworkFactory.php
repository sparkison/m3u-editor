<?php

namespace Database\Factories;

use App\Models\Network;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class NetworkFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Network::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->company() . ' Network',
            'description' => $this->faker->sentence(),
            'logo' => $this->faker->imageUrl(200, 200),
            'channel_number' => $this->faker->numberBetween(1, 999),
            'enabled' => true,
            'schedule_type' => $this->faker->randomElement(['sequential', 'shuffle']),
            'loop_content' => true,
            'user_id' => User::factory(),
        ];
    }

    /**
     * Configure the network as disabled.
     */
    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'enabled' => false,
        ]);
    }

    /**
     * Configure the network with shuffle schedule.
     */
    public function shuffled(): static
    {
        return $this->state(fn (array $attributes) => [
            'schedule_type' => 'shuffle',
        ]);
    }

    /**
     * Configure the network with sequential schedule.
     */
    public function sequential(): static
    {
        return $this->state(fn (array $attributes) => [
            'schedule_type' => 'sequential',
        ]);
    }
}
