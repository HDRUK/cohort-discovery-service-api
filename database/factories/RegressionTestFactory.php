<?php

namespace Database\Factories;

use App\Models\Query;
use App\Models\RegressionTest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class RegressionTestFactory extends Factory
{
    protected $model = RegressionTest::class;

    public function definition(): array
    {
        return [
            'pid' => (string) Str::uuid(),
            'name' => $this->faker->words(3, true),
            'query_id' => Query::factory(),
            'user_id' => null,
        ];
    }

    public function forUser(int $userId): static
    {
        return $this->state(['user_id' => $userId]);
    }
}
