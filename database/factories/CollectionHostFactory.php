<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

use App\Models\Custodian;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CollectionHost>
 */
class CollectionHostFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->company,
            // This likely supersedes the collections table type?
            'query_context_type' => $this->faker->randomElement(['bunny', 'beacon']),
            'client_id' => 'abcd-1234-efgh-5678',
            'client_secret' => 'secret-' . $this->faker->uuid(),
            'custodian_id' => Custodian::all()->random()->id,
        ];
    }
}
