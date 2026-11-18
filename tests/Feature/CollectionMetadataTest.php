<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Custodian;
use App\Models\CustodianHasUser;
use App\Models\User;
use DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CollectionMetadataTest extends TestCase
{
    private const SCHEMA_URL = '/api/v1/collection-schema';
    private const METADATA_URL = '/api/v1/collections/%s/metadata';
    private const COLLECTION_URL = '/api/v1/collections/%s';
    private const MIGRATION = 'migrations/2026_11_18_100000_add_metadata_to_collections_table.php';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        User::truncate();
        Custodian::truncate();
        CustodianHasUser::truncate();
        Collection::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $this->enableMiddleware();
        $this->enableObservers();
        Http::fake();
        Custodian::factory()->create();
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    public function test_it_returns_the_configured_schema(): void
    {
        $response = $this->actingAsJwt($this->admin)->getJson(self::SCHEMA_URL);

        $response->assertOk()
            ->assertJsonPath('data.id', 'CDS@1.0.0')
            ->assertJsonPath('data.schema.properties.summary.properties.name.type', 'string');
    }

    public function test_it_rejects_metadata_that_breaks_the_schema_and_keeps_the_collection(): void
    {
        $collection = Collection::factory()->create(['name' => 'Original name']);

        $response = $this->actingAsJwt($this->admin)->putJson(
            sprintf(self::METADATA_URL, $collection->id),
            ['metadata' => ['summary' => ['name' => 'ab', 'url' => 'not a url']]]
        );

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['summary.name', 'summary.url']]);
        $this->assertSame('Original name', $collection->fresh()->name);
    }

    public function test_it_rejects_a_request_without_metadata(): void
    {
        $collection = Collection::factory()->create();

        $this->actingAsJwt($this->admin)
            ->putJson(sprintf(self::METADATA_URL, $collection->id), [])
            ->assertStatus(422);
    }

    public function test_it_saves_valid_metadata_and_copies_the_core_fields_to_columns(): void
    {
        $collection = Collection::factory()->create();
        $metadata = $this->validMetadata();

        $response = $this->actingAsJwt($this->admin)->putJson(
            sprintf(self::METADATA_URL, $collection->id),
            ['metadata' => $metadata]
        );

        $response->assertOk()->assertJsonPath('data.metadata.access.howToApply', 'Email the custodian.');

        $collection->refresh();
        $this->assertSame('Synthetic diabetes cohort', $collection->name);
        $this->assertSame('People with type 2 diabetes.', $collection->description);
        $this->assertSame('https://example.org/diabetes', $collection->url);
        $this->assertEquals($metadata, $collection->metadata);
        $this->assertSame('CDS@1.0.0', $collection->metadata_schema);
    }

    public function test_it_clears_the_url_column_when_metadata_drops_the_url(): void
    {
        $collection = Collection::factory()->create(['url' => 'https://example.org/old']);
        $metadata = $this->validMetadata();
        unset($metadata['summary']['url']);

        $this->actingAsJwt($this->admin)
            ->putJson(sprintf(self::METADATA_URL, $collection->id), ['metadata' => $metadata])
            ->assertOk();

        $this->assertNull($collection->fresh()->url);
    }

    public function test_it_writes_column_edits_back_into_the_metadata(): void
    {
        $collection = Collection::factory()->create();
        $collection->update(['metadata' => $this->validMetadata()]);

        $this->actingAsJwt($this->admin)
            ->putJson(sprintf(self::COLLECTION_URL, $collection->id), ['name' => 'Renamed cohort'])
            ->assertOk();

        $metadata = $collection->fresh()->metadata;
        $this->assertSame('Renamed cohort', $metadata['summary']['name']);
        $this->assertSame('Email the custodian.', $metadata['access']['howToApply']);
    }

    public function test_it_seeds_metadata_from_the_columns_of_a_new_collection(): void
    {
        $collection = Collection::factory()->create([
            'name' => 'New cohort',
            'description' => 'A description.',
            'url' => null,
        ]);

        $this->assertSame(
            ['summary' => ['name' => 'New cohort', 'description' => 'A description.']],
            $collection->fresh()->metadata
        );
        $this->assertSame('CDS@1.0.0', $collection->fresh()->metadata_schema);
    }

    public function test_it_shows_metadata_to_a_user_who_can_see_the_collection(): void
    {
        $custodian = Custodian::factory()->create();
        $member = User::factory()->create();
        $member->custodians()->attach($custodian->id);
        $collection = Collection::factory()->create(['custodian_id' => $custodian->id]);

        $this->actingAsJwt($member)
            ->getJson(sprintf(self::METADATA_URL, $collection->pid))
            ->assertOk()
            ->assertJsonPath('data.pid', (string) $collection->pid)
            ->assertJsonPath('data.metadata_schema', 'CDS@1.0.0')
            ->assertJsonPath('data.metadata.summary.name', $collection->name);
    }

    public function test_it_hides_metadata_from_a_user_who_cannot_see_the_collection(): void
    {
        $outsider = User::factory()->create();
        $collection = Collection::factory()->create();

        $this->actingAsJwt($outsider)
            ->getJson(sprintf(self::METADATA_URL, $collection->pid))
            ->assertNotFound();
    }

    public function test_it_leaves_metadata_out_of_the_plain_collection_response(): void
    {
        $collection = Collection::factory()->create();

        $this->actingAsJwt($this->admin)
            ->getJson(sprintf(self::COLLECTION_URL, $collection->id))
            ->assertOk()
            ->assertJsonMissingPath('data.metadata');
    }

    public function test_the_migration_backfills_metadata_from_existing_columns(): void
    {
        $this->disableObservers();
        $collection = Collection::factory()->create([
            'name' => 'Existing cohort',
            'description' => 'Already here.',
            'url' => 'https://example.org/existing',
        ]);
        $migration = require database_path(self::MIGRATION);

        $migration->down();
        $migration->up();

        $row = DB::table('collections')->where('id', $collection->id)->first();
        $this->assertSame('CDS@1.0.0', $row->metadata_schema);
        $this->assertEquals(
            ['summary' => [
                'name' => 'Existing cohort',
                'description' => 'Already here.',
                'url' => 'https://example.org/existing',
            ]],
            json_decode($row->metadata, true)
        );
    }

    private function validMetadata(): array
    {
        return [
            'summary' => [
                'name' => 'Synthetic diabetes cohort',
                'description' => 'People with type 2 diabetes.',
                'url' => 'https://example.org/diabetes',
                'keywords' => ['diabetes', 'primary care'],
                'contactPoint' => 'data.team@example.org',
            ],
            'access' => [
                'howToApply' => 'Email the custodian.',
            ],
        ];
    }
}
