<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Pennant\Feature;
use Tests\TestCase;

class QueryParserTest extends TestCase
{
    private const BASE_URL = '/api/v1/parse-query';

    private const NLP_BASE = 'http://nlp-test';

    private array $minimalNlpResponse = [
        'entities' => [],
        'groups' => [],
        'root_operator' => null,
        'root_groups' => [],
        'age_constraints' => [],
        'time_constraints' => [],
        'death_constraints' => null,
        'warnings' => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.nlp.base_uri', self::NLP_BASE);
        Feature::deactivateForEveryone('query-builder-use-stats-in-ordering');
        Feature::deactivateForEveryone('query-builder-use-collections-in-search');
        Feature::deactivateForEveryone('query-builder-use-demographic-rule');
    }

    private function parsedResult(\Illuminate\Testing\TestResponse $response): array
    {
        return json_decode($response->json('data'), true);
    }

    public function test_true(): void
    {
        $this->assertTrue(true);
    }

    public function test_use_stats_ordering_sent_when_flag_active(): void
    {
        Feature::for(null)->activate('query-builder-use-stats-in-ordering');

        Http::fake([self::NLP_BASE . '/extract*' => Http::response($this->minimalNlpResponse, 200)]);

        $this->postJson(self::BASE_URL, ['query' => 'diabetes'])->assertOk();

        Http::assertSent(fn($r) => str_contains($r->url(), '/extract') && $r->data()['use_stats_ordering'] === true);
    }

    public function test_use_stats_ordering_not_sent_when_flag_inactive(): void
    {
        Http::fake([self::NLP_BASE . '/extract*' => Http::response($this->minimalNlpResponse, 200)]);

        $this->postJson(self::BASE_URL, ['query' => 'diabetes'])->assertOk();

        Http::assertSent(fn($r) => str_contains($r->url(), '/extract') && $r->data()['use_stats_ordering'] === false);
    }

    public function test_use_collection_filter_sent_when_flag_active(): void
    {
        Feature::for(null)->activate('query-builder-use-collections-in-search');

        Http::fake([self::NLP_BASE . '/extract*' => Http::response($this->minimalNlpResponse, 200)]);

        $this->postJson(self::BASE_URL, ['query' => 'diabetes'])->assertOk();

        Http::assertSent(fn($r) => str_contains($r->url(), '/extract') && $r->data()['use_collection_filter'] === true);
    }

    public function test_collection_ids_forwarded_to_nlp(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('collections')->insert([
            'pid' => 'test-collection-pid',
            'name' => 'Test Collection',
            'type' => 'BUNNY',
            'url' => 'http://localhost',
            'status' => 1,
            'custodian_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        $collectionId = (int) DB::table('collections')->where('pid', 'test-collection-pid')->value('id');

        Http::fake([self::NLP_BASE . '/extract*' => Http::response($this->minimalNlpResponse, 200)]);

        $this->postJson(self::BASE_URL, [
            'query' => 'diabetes',
            'collections' => ['test-collection-pid'],
        ])->assertOk();

        Http::assertSent(fn($r) => str_contains($r->url(), '/extract') && $r->data()['collection_ids'] === [$collectionId]);
    }

    public function test_parse_returns_422_for_invalid_collection_ids(): void
    {
        $this->postJson(self::BASE_URL, [
            'query' => 'diabetes',
            'collections' => 'not-an-array',
        ])->assertUnprocessable();
    }

    public function test_use_collection_filter_not_sent_when_flag_inactive(): void
    {
        Http::fake([self::NLP_BASE . '/extract*' => Http::response($this->minimalNlpResponse, 200)]);

        $this->postJson(self::BASE_URL, ['query' => 'diabetes'])->assertOk();

        Http::assertSent(fn($r) => str_contains($r->url(), '/extract') && $r->data()['use_collection_filter'] === false);
    }

    /**
     * An /extract response for "women under 65 with ca125 who died": the gender concept
     * (8532) sits among fuzzy noise on the "FEMALE" span, and age is a
     * query-scope constraint, and have a death record.
     */
    private function demographicNlpResponse(): array
    {
        return [
            'entities' => [
                // "FEMALE" span: the real gender concept ranks above a
                // fuzzy-noise sibling candidate.
                [
                    'text' => 'FEMALE',
                    'label' => 'Gender',
                    'attributes' => [
                        'concept_id' => 8532,
                        'concept_name' => 'FEMALE',
                        'domain_id' => 'Gender',
                        'match_score' => 900,
                    ],
                ],
                [
                    'text' => 'FEMALE',
                    'label' => 'Condition',
                    'attributes' => [
                        'concept_id' => 40481087,
                        'concept_name' => 'Female genital tract problem',
                        'domain_id' => 'Condition',
                        'match_score' => 500,
                    ],
                ],
                // Clinical span.
                [
                    'text' => 'ca125',
                    'label' => 'Measurement',
                    'attributes' => [
                        'concept_id' => 44811969,
                        'concept_name' => 'Serum CA 125 (cancer antigen 125) measurement',
                        'domain_id' => 'Measurement',
                        'match_score' => 1500,
                    ],
                ],
            ],
            'groups' => [],
            'root_operator' => null,
            'root_groups' => [],
            'age_constraints' => [
                ['min' => null, 'max' => 65, 'inclusive' => false, 'scope' => 'query'],
            ],
            'time_constraints' => [],
            'death_constraints' => 1,
            'warnings' => [],
        ];
    }

    public function test_demographics_absent_and_default_max_matches_when_flag_inactive(): void
    {
        Http::fake([self::NLP_BASE . '/extract*' => Http::response($this->minimalNlpResponse, 200)]);

        $response = $this->postJson(self::BASE_URL, ['query' => 'diabetes'])->assertOk();

        $this->assertArrayNotHasKey('demographics', $this->parsedResult($response));
        Http::assertSent(fn($r) => str_contains($r->url(), '/extract') && str_contains($r->url(), 'max_matches=10'));
    }

    public function test_demographics_block_built_when_flag_active(): void
    {
        Feature::for(null)->activate('query-builder-use-demographic-rule');

        Http::fake([self::NLP_BASE . '/extract*' => Http::response($this->demographicNlpResponse(), 200)]);

        $response = $this->postJson(self::BASE_URL, ['query' => 'women under 65 with ca125 who died'])->assertOk();

        $parsed = $this->parsedResult($response);

        $this->assertSame([
            'age' => [0, 65],
            'sex' => [
                ['concept_id' => 8532, 'name' => 'Female', 'category' => 'Gender'],
            ],
            'race' => [],
            'death' => ['value' => 1],
        ], $parsed['demographics']);
    }

    public function test_demographics_use_default_max_matches_when_flag_active(): void
    {
        Feature::for(null)->activate('query-builder-use-demographic-rule');

        Http::fake([self::NLP_BASE . '/extract*' => Http::response($this->demographicNlpResponse(), 200)]);

        $this->postJson(self::BASE_URL, ['query' => 'women under 65 with ca125 who died'])->assertOk();

        // The gender concept now ranks high, so no elevated max_matches is needed.
        Http::assertSent(fn($r) => str_contains($r->url(), '/extract') && str_contains($r->url(), 'max_matches=10'));
    }

    public function test_gender_span_and_age_stripped_from_rules_when_flag_active(): void
    {
        Feature::for(null)->activate('query-builder-use-demographic-rule');

        Http::fake([self::NLP_BASE . '/extract*' => Http::response($this->demographicNlpResponse(), 200)]);

        $response = $this->postJson(self::BASE_URL, ['query' => 'women under 65 with ca125 who died'])->assertOk();

        $parsed = $this->parsedResult($response);
        $encoded = json_encode($parsed['rules']);

        // The gender span (and its fuzzy-noise sibling candidate) must not appear as clinical rules.
        $this->assertStringNotContainsString('8532', $encoded);
        $this->assertStringNotContainsString('40481087', $encoded);

        // Query-scope age lives in demographics, not as an inline age-filter node in rules.
        $this->assertStringNotContainsString('"value":[0,65]', $encoded);

        // The genuine clinical concept survives.
        $this->assertStringContainsString('44811969', $encoded);
    }
}
