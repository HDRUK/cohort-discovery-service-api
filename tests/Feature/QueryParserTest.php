<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
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
        'warnings' => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.nlp.base_uri', self::NLP_BASE);
        Feature::deactivateForEveryone('query-builder-use-stats-in-ordering');
        Feature::deactivateForEveryone('query-builder-use-collections-in-search');
    }

    public function test_true(): void
    {
        $this->assertTrue(true);
    }

    public function test_parse_sends_use_stats_ordering_when_feature_flag_active(): void
    {
        Feature::activateForEveryone('query-builder-use-stats-in-ordering');

        Http::fake([
            self::NLP_BASE . '/extract*' => Http::response($this->minimalNlpResponse, 200),
        ]);

        $this->postJson(self::BASE_URL, ['query' => 'diabetes'])->assertOk();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/extract')
                && $request->data()['use_stats_ordering'] === true;
        });
    }

    public function test_parse_sends_use_collection_filter_when_feature_flag_active(): void
    {
        Feature::activateForEveryone('query-builder-use-collections-in-search');

        Http::fake([
            self::NLP_BASE . '/extract*' => Http::response($this->minimalNlpResponse, 200),
        ]);

        $this->postJson(self::BASE_URL, ['query' => 'diabetes'])->assertOk();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/extract')
                && $request->data()['use_collection_filter'] === true;
        });
    }

    public function test_parse_sends_collection_ids_to_nlp(): void
    {
        Http::fake([
            self::NLP_BASE . '/extract*' => Http::response($this->minimalNlpResponse, 200),
        ]);

        $collectionIds = ['col-abc', 'col-def'];

        $this->postJson(self::BASE_URL, [
            'query' => 'diabetes',
            'collection_ids' => $collectionIds,
        ])->assertOk();

        Http::assertSent(function ($request) use ($collectionIds) {
            return str_contains($request->url(), '/extract')
                && $request->data()['collection_ids'] === $collectionIds;
        });
    }

    public function test_parse_sends_all_nlp_params_when_both_flags_active(): void
    {
        Feature::activateForEveryone('query-builder-use-stats-in-ordering');
        Feature::activateForEveryone('query-builder-use-collections-in-search');

        Http::fake([
            self::NLP_BASE . '/extract*' => Http::response($this->minimalNlpResponse, 200),
        ]);

        $this->postJson(self::BASE_URL, [
            'query' => 'hypertension',
            'collection_ids' => ['col-1', 'col-2'],
        ])->assertOk();

        Http::assertSent(function ($request) {
            $data = $request->data();
            return str_contains($request->url(), '/extract')
                && $data['use_stats_ordering'] === true
                && $data['use_collection_filter'] === true
                && $data['collection_ids'] === ['col-1', 'col-2'];
        });
    }

    public function test_parse_defaults_flags_to_false_when_inactive(): void
    {
        Http::fake([
            self::NLP_BASE . '/extract*' => Http::response($this->minimalNlpResponse, 200),
        ]);

        $this->postJson(self::BASE_URL, ['query' => 'diabetes'])->assertOk();

        Http::assertSent(function ($request) {
            $data = $request->data();
            return str_contains($request->url(), '/extract')
                && $data['use_stats_ordering'] === false
                && $data['use_collection_filter'] === false;
        });
    }

    public function test_parse_returns_422_for_invalid_collection_ids(): void
    {
        $this->postJson(self::BASE_URL, [
            'query' => 'diabetes',
            'collection_ids' => 'not-an-array',
        ])->assertUnprocessable();
    }
}
