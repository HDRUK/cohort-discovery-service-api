<?php

namespace Database\Factories;

use App\Models\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use App\Services\QueryContext\QueryContextType;

class CollectionFactory extends Factory
{
    protected $model = Collection::class;

    public function definition(): array
    {
        return [
            'pid' => Str::uuid(),
            'name' => $this->faker->words(3, true),
            'type' => $this->faker->randomElement([
                QueryContextType::Bunny,
                QueryContextType::Beacon,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    public function bunny(): static
    {
        return $this->state([
            'type' => QueryContextType::Bunny,
        ]);
    }

    public function beacon(): static
    {
        return $this->state([
            'type' => QueryContextType::Beacon,
        ]);
    }
}
