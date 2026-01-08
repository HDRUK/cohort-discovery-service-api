<?php

namespace Tests\Feature;

use App\Enums\QueryType;
use App\Models\Collection;
use App\Models\Custodian;
use App\Models\Query;
use App\Models\Task;
use App\Models\User;
use DB;
use Tests\TestCase;

class DistributionTest extends TestCase
{
    private const BASE_URL = '/api/v1/collection/%s/distributions/run-manually';

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

        $type = QueryType::DEMOGRAPHICS->value;

        $response = $this->actingAsJwt(
            $this->user,
            $overrides
        )
            ->postJson(
                sprintf(self::BASE_URL, $collection->pid, ),
                [
                    'query_type' => $type
                ]
            );

        $response->assertStatus(200);
        $content = $response->json('data');

        $name = sprintf('%s-%s', $collection->name, $type);
        $query = Query::where('name', 'like', '%'.$name.'%')->first();
        $task = Task::where('query_id', $query->id)->first();

        $this->assertNotNull($content);
        $this->assertTrue($content['id'] === $query->id);
        $this->assertTrue($content['tasks'][0]['id'] === $task->id);
    }
}
