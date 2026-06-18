<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Custodian;
use App\Models\CustodianHasUser;
use App\Models\User;
use App\Models\UserHasWorkgroup;
use App\Models\Workgroup;
use App\Models\WorkgroupHasCollection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Covers the Term Directory endpoint. The key behaviour under test is that the
 * aggregate count / ncollections must reflect ONLY the collections a given user
 * is allowed to see - so the same concept reports different totals for an admin,
 * a workgroup user and a custodian user. A test that only checked "rows come
 * back" could never catch a regression where per-user filtering is dropped and
 * everyone sees the global figures.
 */
class TermDirectoryControllerTest extends TestCase
{
    private const URL = '/api/v1/term-directory';

    // OMOP concept ids that exist in MinimalOmopSeeder, with their domains.
    private const CONCEPT_CONDITION = 36685455;   // domain_id Condition
    private const CONCEPT_MEASUREMENT = 4269135;  // domain_id Measurement
    private const CONCEPT_OBSERVATION = 37310453; // domain_id Observation

    private const NAME_MEASUREMENT = 'Respiratory volume - finding';

    private User $admin;
    private User $workgroupUser;
    private User $custodianUser;
    private User $noAccessUser;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('distributions')->truncate();
        WorkgroupHasCollection::truncate();
        UserHasWorkgroup::truncate();
        CustodianHasUser::truncate();
        Collection::truncate();
        Workgroup::truncate();
        Custodian::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $this->enableMiddleware();

        $ownedCustodian = Custodian::factory()->create();
        $otherCustodian = Custodian::factory()->create();

        $workgroup = Workgroup::create(['name' => 'WG One', 'active' => 1]);

        // collA: in the workgroup AND active -> visible to the workgroup user.
        $collA = Collection::factory()->create(['custodian_id' => $otherCustodian->id]);
        $collA->setState(Collection::STATUS_ACTIVE);
        $collA->workgroups()->attach($workgroup->id);

        // collB: owned by $ownedCustodian, left non-active -> visible to the
        // custodian user via the custodian path (which ignores state).
        $collB = Collection::factory()->create(['custodian_id' => $ownedCustodian->id]);

        // collC: active but in no workgroup and a custodian nobody administers
        // -> only visible to the admin.
        $collC = Collection::factory()->create(['custodian_id' => $otherCustodian->id]);
        $collC->setState(Collection::STATUS_ACTIVE);

        // The Condition concept appears in BOTH collA and collB with different
        // counts so we can prove per-user aggregation.
        $this->insertDistribution($collA->id, self::CONCEPT_CONDITION, 100);
        $this->insertDistribution($collB->id, self::CONCEPT_CONDITION, 7);
        $this->insertDistribution($collB->id, self::CONCEPT_MEASUREMENT, 50);
        $this->insertDistribution($collC->id, self::CONCEPT_OBSERVATION, 30);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->workgroupUser = User::factory()->create();
        $this->workgroupUser->workgroups()->attach($workgroup->id);

        $this->custodianUser = User::factory()->create();
        $this->custodianUser->custodians()->attach($ownedCustodian->id);

        $this->noAccessUser = User::factory()->create();
    }

    private function insertDistribution(int $collectionId, int $conceptId, int $count): void
    {
        DB::table('distributions')->insert([
            'collection_id' => $collectionId,
            'name'          => 'CONCEPT_' . $conceptId,
            'category'      => 'CONDITION',
            'description'   => 'seeded distribution',
            'concept_id'    => $conceptId,
            'count'         => $count,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>> rows keyed by concept_id
     */
    private function rowsByConceptId(array $rows): array
    {
        $keyed = [];
        foreach ($rows as $row) {
            $keyed[$row['concept_id']] = $row;
        }

        return $keyed;
    }

    public function test_admin_sees_all_concepts_with_globally_aggregated_counts(): void
    {
        $response = $this->actingAsJwt($this->admin)->getJson(self::URL);

        $response->assertOk();
        $this->assertEquals(3, $response->json('data.total'));

        $rows = $this->rowsByConceptId($response->json('data.data'));

        // Condition concept is summed across collA (100) + collB (7).
        $this->assertEquals(107, $rows[self::CONCEPT_CONDITION]['count']);
        $this->assertEquals(2, $rows[self::CONCEPT_CONDITION]['ncollections']);
        $this->assertEquals(50, $rows[self::CONCEPT_MEASUREMENT]['count']);
        $this->assertEquals(30, $rows[self::CONCEPT_OBSERVATION]['count']);
    }

    public function test_workgroup_user_only_sees_their_active_workgroup_collections(): void
    {
        $response = $this->actingAsJwt($this->workgroupUser)->getJson(self::URL);

        $response->assertOk();
        $rows = $this->rowsByConceptId($response->json('data.data'));

        // Only collA is reachable, so only the Condition concept appears and its
        // count is 100 (collA) - NOT 107 - proving counts are scoped to access.
        $this->assertEquals(1, $response->json('data.total'));
        $this->assertArrayHasKey(self::CONCEPT_CONDITION, $rows);
        $this->assertEquals(100, $rows[self::CONCEPT_CONDITION]['count']);
        $this->assertEquals(1, $rows[self::CONCEPT_CONDITION]['ncollections']);
        $this->assertArrayNotHasKey(self::CONCEPT_MEASUREMENT, $rows);
        $this->assertArrayNotHasKey(self::CONCEPT_OBSERVATION, $rows);
    }

    public function test_custodian_user_sees_their_custodian_collections_regardless_of_state(): void
    {
        $response = $this->actingAsJwt($this->custodianUser)->getJson(self::URL);

        $response->assertOk();
        $rows = $this->rowsByConceptId($response->json('data.data'));

        // collB is reachable via custodian access even though it is not active.
        $this->assertEquals(2, $response->json('data.total'));
        $this->assertEquals(50, $rows[self::CONCEPT_MEASUREMENT]['count']);
        // Same Condition concept, but now scoped to collB only -> count 7.
        $this->assertEquals(7, $rows[self::CONCEPT_CONDITION]['count']);
        $this->assertArrayNotHasKey(self::CONCEPT_OBSERVATION, $rows);
    }

    public function test_user_with_no_accessible_collections_gets_empty_result(): void
    {
        $response = $this->actingAsJwt($this->noAccessUser)->getJson(self::URL);

        $response->assertOk();
        $this->assertEquals(0, $response->json('data.total'));
        $this->assertCount(0, $response->json('data.data'));
    }

    public function test_domain_id_filter_restricts_results(): void
    {
        $response = $this->actingAsJwt($this->admin)
            ->getJson(self::URL . '?domain_id=Measurement');

        $response->assertOk();
        $data = $response->json('data.data');

        $this->assertCount(1, $data);
        $this->assertEquals(self::CONCEPT_MEASUREMENT, $data[0]['concept_id']);
        $this->assertEquals('Measurement', $data[0]['domain_id']);
    }

    public function test_concept_name_search_matches_partial_name(): void
    {
        $response = $this->actingAsJwt($this->admin)
            ->getJson(self::URL . '?concept_name=Respiratory');

        $response->assertOk();
        $data = $response->json('data.data');

        $this->assertCount(1, $data);
        $this->assertEquals(self::NAME_MEASUREMENT, $data[0]['concept_name']);
    }

    public function test_concept_id_search_matches_exact_id(): void
    {
        $response = $this->actingAsJwt($this->admin)
            ->getJson(self::URL . '?concept_id=' . self::CONCEPT_OBSERVATION);

        $response->assertOk();
        $data = $response->json('data.data');

        $this->assertCount(1, $data);
        $this->assertEquals(self::CONCEPT_OBSERVATION, $data[0]['concept_id']);
    }

    public function test_results_default_to_count_descending(): void
    {
        $response = $this->actingAsJwt($this->admin)->getJson(self::URL);

        $response->assertOk();
        $ids = array_column($response->json('data.data'), 'concept_id');

        // counts: Condition 107 > Measurement 50 > Observation 30
        $this->assertEquals(
            [self::CONCEPT_CONDITION, self::CONCEPT_MEASUREMENT, self::CONCEPT_OBSERVATION],
            $ids
        );
    }

    public function test_sort_by_concept_name_ascending(): void
    {
        $response = $this->actingAsJwt($this->admin)
            ->getJson(self::URL . '?sort=concept_name:asc');

        $response->assertOk();
        $names = array_column($response->json('data.data'), 'concept_name');
        $sorted = $names;
        sort($sorted, SORT_STRING);

        $this->assertEquals($sorted, $names);
    }

    public function test_pagination_limits_and_reports_total(): void
    {
        $response = $this->actingAsJwt($this->admin)
            ->getJson(self::URL . '?per_page=2&page=1');

        $response->assertOk();
        $this->assertCount(2, $response->json('data.data'));
        $this->assertEquals(3, $response->json('data.total'));
        $this->assertEquals(2, $response->json('data.per_page'));
        $this->assertEquals(1, $response->json('data.current_page'));
    }

    public function test_response_rows_have_expected_shape(): void
    {
        $response = $this->actingAsJwt($this->admin)->getJson(self::URL);

        $response->assertOk();
        $item = $response->json('data.data.0');

        $this->assertArrayHasKey('concept_id', $item);
        $this->assertArrayHasKey('concept_name', $item);
        $this->assertArrayHasKey('domain_id', $item);
        $this->assertArrayHasKey('count', $item);
        $this->assertArrayHasKey('ncollections', $item);
    }
}
