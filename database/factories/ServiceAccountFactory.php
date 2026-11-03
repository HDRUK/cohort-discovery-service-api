<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ServiceAccount>
 */
class ServiceAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->slug(2),
            'client_id' => (string) Str::uuid(),
            'client_secret' => Str::random(64),
        ];
    }
}
