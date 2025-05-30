<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use App\Models\Channel;
use App\Models\ChannelFailover;
use App\Models\User;

class ChannelFailoverFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = ChannelFailover::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'channel_id' => Channel::factory(),
            'channel_failover_id' => Channel::factory()->create()->failover_id,
            'sort' => fake()->randomNumber(),
            'metadata' => '{}',
        ];
    }
}
