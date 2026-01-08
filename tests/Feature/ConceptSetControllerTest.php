<?php

namespace Tests\Feature\Api\V1;

use App\Models\ConceptSet;
use App\Models\ConceptSetHasConcept;
use App\Models\Distribution;
use App\Models\User;
use Tests\TestCase;

class ConceptSetControllerTest extends TestCase
{
    private const BASE_URL = '/api/v1/concept_sets';

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableObservers();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_validates_input_when_creating_concept_set()
    {
        $this->enableMiddleware();
        $user = User::factory()->create();

        $response = $this->actingAsJwt($user)->postJson(self::BASE_URL, []);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'domain']);

        $response = $this->actingAsJwt($user)->postJson(self::BASE_URL, [
            'name' => 'COVID-19 Vaccines',
            'description' => 'my definition of COVID-19 vaccines',
            'domain' => 'Drug',
        ]);
        $response->assertCreated();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_creates_and_lists_concept_sets_for_the_correct_user()
    {
        $this->enableMiddleware();
        $owner = User::factory()->create();
        $another = User::factory()->create();

        // Create (as owner)
        $payload = [
            'name' => 'COVID-19 Vaccines',
            'description' => 'my definition of COVID-19 vaccines',
            'domain' => 'Drug',
        ];

        $create = $this->actingAsJwt($owner)->postJson(self::BASE_URL, $payload);
        $create->assertCreated();

        $this->assertDatabaseHas(ConceptSet::class, [
            'name' => $payload['name'],
            'domain' => $payload['domain'],
            'user_id' => $owner->id,
        ]);

        $indexOwner = $this->actingAsJwt($owner)->get(self::BASE_URL);
        $indexOwner->assertSuccessful();

        $this->assertEquals(1, count($indexOwner->json('data')));

        $indexAnother = $this->actingAsJwt($another)->get(self::BASE_URL);
        $indexAnother->assertSuccessful();
        $this->assertEquals(0, count($indexAnother->json('data')));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_filters_index_by_domain()
    {
        $this->enableMiddleware();
        $user = User::factory()->create();

        ConceptSet::factory()->for($user)->create(['domain' => 'drug']);
        ConceptSet::factory()->for($user)->create(['domain' => 'observation']);

        $response = $this->actingAsJwt($user)->get(self::BASE_URL.'?domain=drug');
        $response->assertSuccessful();

        $items = $response->json('data');
        $this->assertCount(1, $items);
        $this->assertEquals('drug', $items[0]['domain']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_shows_concept_set_with_correct_auth()
    {
        $this->enableMiddleware();
        $owner = User::factory()->create();
        $another = User::factory()->create();

        $conceptSet = ConceptSet::factory()->for($owner)->create([
            'domain' => 'Drug',
            'name' => 'COVID-19 Vaccines',
        ]);

        $response = $this->actingAsJwt($owner)->get(self::BASE_URL.'/'.$conceptSet->id);
        $response->assertSuccessful();

        $response = $this->actingAsJwt($another)->get(self::BASE_URL.'/'.$conceptSet->id);
        $response->assertForbidden();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_attaches_a_concept_when_domain_matches_and_concept_exists()
    {
        $this->enableMiddleware();
        $user = User::factory()->create();
        $another = User::factory()->create();

        $conceptSet = ConceptSet::factory()->for($user)->create([
            'domain' => 'Drug',
        ]);

        $conceptId = 12345;

        Distribution::create([
            'collection_id' => 1,
            'name' => $conceptId,
            'count' => 100,
            'concept_id' => $conceptId,
            'category' => 'Drug',
            'description' => 'Some drug',
        ]);

        $this->actingAsJwt($user)
            ->postJson(self::BASE_URL.'/'.$conceptSet->id.'/attach/'.$conceptId)
            ->assertCreated();

        $this->actingAsJwt($another)
            ->postJson(self::BASE_URL.'/'.$conceptSet->id.'/attach/'.$conceptId)
            ->assertForbidden();

        $this->assertDatabaseHas(ConceptSetHasConcept::class, [
            'concept_set_id' => $conceptSet->id,
            'concept_id' => $conceptId,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_rejects_attach_when_concept_not_found()
    {
        $this->enableMiddleware();
        $user = User::factory()->create();

        $conceptSet = ConceptSet::factory()->for($user)->create(['domain' => 'Drug']);
        $missingConceptId = 999999;

        $resp = $this->actingAsJwt($user)
            ->postJson(self::BASE_URL.'/'.$conceptSet->id.'/attach/'.$missingConceptId);

        $resp->assertNotFound();
        $this->assertDatabaseMissing(ConceptSetHasConcept::class, [
            'concept_set_id' => $conceptSet->id,
            'concept_id' => $missingConceptId,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_rejects_attach_when_domain_mismatches()
    {
        $this->enableMiddleware();
        $user = User::factory()->create();

        $conceptSet = ConceptSet::factory()->for($user)->create(['domain' => 'biology']);

        $conceptId = 4321;
        Distribution::create([
            'collection_id' => 1,
            'name' => $conceptId,
            'count' => 100,
            'concept_id' => $conceptId,
            'category' => 'Observation',
            'description' => 'Some drug',
        ]);

        $resp = $this->actingAsJwt($user)
            ->postJson(self::BASE_URL.'/'.$conceptSet->id.'/attach/'.$conceptId);

        $resp->assertStatus(422)
            ->assertJsonValidationErrors(['concept_id']);
        $this->assertDatabaseMissing(ConceptSetHasConcept::class, [
            'concept_set_id' => $conceptSet->id,
            'concept_id' => $conceptId,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_detaches_a_concept()
    {
        $this->enableMiddleware();
        $user = User::factory()->create();
        $another = User::factory()->create();

        $conceptSet = ConceptSet::factory()->for($user)->create(['domain' => 'Drug']);
        $conceptId = 3333;

        Distribution::create([
            'collection_id' => 1,
            'name' => $conceptId,
            'count' => 100,
            'concept_id' => $conceptId,
            'category' => 'Drug',
            'description' => 'Some drug',
        ]);

        $this->actingAsJwt($user)
            ->postJson(self::BASE_URL.'/'.$conceptSet->id.'/attach/'.$conceptId)
            ->assertCreated();

        $this->actingAsJwt($another)
            ->postJson(self::BASE_URL.'/'.$conceptSet->id.'/attach/'.$conceptId)
            ->assertForbidden();

        $this->assertDatabaseHas(ConceptSetHasConcept::class, [
            'concept_set_id' => $conceptSet->id,
            'concept_id' => $conceptId,
        ]);

        $response = $this->actingAsJwt($user)
            ->deleteJson(self::BASE_URL.'/'.$conceptSet->id.'/detach/'.$conceptId);

        $response->assertSuccessful();

        $this->assertDatabaseMissing(ConceptSetHasConcept::class, [
            'concept_set_id' => $conceptSet->id,
            'concept_id' => $conceptId,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_clears_a_concept_set()
    {
        $this->enableMiddleware();
        $user = User::factory()->create();

        $conceptSet = ConceptSet::factory()->for($user)->create(['domain' => 'Drug']);

        $ids = [4444, 5555];
        foreach ($ids as $conceptId) {
            Distribution::create([
                'collection_id' => 1,
                'name' => $conceptId,
                'count' => 100,
                'concept_id' => $conceptId,
                'category' => 'Drug',
                'description' => 'Some drug',
            ]);
            $this->actingAsJwt($user)
                ->postJson(self::BASE_URL.'/'.$conceptSet->id.'/attach/'.$conceptId)
                ->assertCreated();
        }

        $response = $this->actingAsJwt($user)
            ->deleteJson(self::BASE_URL.'/'.$conceptSet->id.'/clear');

        $response->assertSuccessful();
        foreach ($ids as $cid) {
            $this->assertDatabaseMissing(ConceptSetHasConcept::class, [
                'concept_set_id' => $conceptSet->id,
                'concept_id' => $cid,
            ]);
        }
    }
}
