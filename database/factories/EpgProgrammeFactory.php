<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use App\Models\Epg;
use App\Models\EpgProgramme;
use App\Models\User;

class EpgProgrammeFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = EpgProgramme::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'channel_id' => $this->faker->word(),
            'import_batch_no' => $this->faker->word(),
            'data' => $this->faker->text(),
            'user_id' => User::factory(),
            'epg_id' => Epg::factory(),
        ];
    }
}
