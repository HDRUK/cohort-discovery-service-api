<?php

namespace Database\Factories;

use App\Models\Query;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class QueryFactory extends Factory
{
    protected $model = Query::class;

    public function definition(): array
    {
        return [
            'pid' => Str::uuid(),
            'name' => $this->faker->sentence,
            'definition' =>  [
                'combinator' => $this->faker->randomElement(['and', 'or']),
                'rules' => [
                    [
                        'field' => $this->faker->randomElement(['sex', 'age', 'condition']),
                        'operator' => $this->faker->randomElement(['=', '!=', '>', '<']),
                        'value' => (string) $this->faker->numberBetween(1000, 9999),
                        'id' => (string) Str::uuid(),
                    ]
                ],
                'id' => (string) Str::uuid(),
            ],
        ];
    }
}
