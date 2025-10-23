<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Enums\TaskType;
use App\Models\CollectionConfig;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CollectionConfig>
 */
class CollectionConfigFactory extends Factory
{
    protected $model = CollectionConfig::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'collection_id' => 1,
            'run_time_hour' => 0,
            'run_time_minute' => 0,
            'frequency_mode' => 1,
            'run_time_frequency' => 1,
            'enabled' => $this->faker->randomElement([0, 1]),
            'type' => $this->faker->randomElement([
                TaskType::A,
                TaskType::B,
            ]),
        ];
    }
}
