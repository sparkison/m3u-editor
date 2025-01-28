<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use App\Models\Epg;
use App\Models\EpgChannel;

class EpgChannelFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = EpgChannel::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'display_name' => $this->faker->word(),
            'lang' => $this->faker->word(),
            'channel_id' => $this->faker->word(),
            'epg_id' => Epg::factory(),
            'programmes' => $this->faker->text(),
        ];
    }
}
