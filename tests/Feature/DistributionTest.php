<?php

namespace Tests\Feature;

use DB;
use Tests\TestCase;
use App\Models\User;
use App\Models\Query;
use App\Models\Task;
use App\Models\Custodian;
use App\Models\Collection;

class DistributionTest extends TestCase
{
    private const BASE_URL  = '/api/v1/distributions/run-manually';
    private User $user;

    private array $adminWorkgroup = [
        'workgroups' => [
            'id' => 1,
            'name' => 'admin',
            'enabled' => 1,
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        User::truncate();
        Custodian::truncate();
        Collection::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $this->enableMiddleware();
        $this->user = User::factory()->create();
    }

    public function test_it_can_manually_trigger_distribution_jobs(): void
    {
        $this->disableObservers();

        $custodian = Custodian::factory()->create([
            'name' => 'Custodian For Testing',
        ]);

        $collection = Collection::factory()->create([
            'custodian_id' => $custodian->id,
        ]);

        $overrides = [
            'user' => [
                'workgroups' => [[
                    'id' => 1,
                    'name' => 'admin',
                    'enabled' => 1,
                ]],
            ],
        ];

        $response = $this->actingAsJwt(
            $this->user,
            $overrides
        )
        ->postJson(
            self::BASE_URL,
            [
            'collection_id' => $collection->id,
        ]
        );

        $response->assertStatus(200);
        $content = $response->json('data');

        $query = Query::where('name', 'manual-run-' . str_replace(' ', '-', $collection->name))->first();
        $task = Task::where('query_id', $query->id)->first();

        $this->assertNotNull($content);
        $this->assertTrue($content['query']['id'] === $query->id);
        $this->assertTrue($content['task']['id'] === $task->id);
    }
}
