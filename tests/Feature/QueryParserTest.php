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

    public function test_use_stats_ordering_sent_when_flag_active(): void
    {
        Feature::activateForEveryone('query-builder-use-stats-in-ordering');

        Http::fake([self::NLP_BASE . '/extract*' => Http::response($this->minimalNlpResponse, 200)]);

        $this->postJson(self::BASE_URL, ['query' => 'diabetes'])->assertOk();

        Http::assertSent(fn ($r) => str_contains($r->url(), '/extract') && $r->data()['use_stats_ordering'] === true);
    }

    public function test_use_stats_ordering_not_sent_when_flag_inactive(): void
    {
        Http::fake([self::NLP_BASE . '/extract*' => Http::response($this->minimalNlpResponse, 200)]);

        $this->postJson(self::BASE_URL, ['query' => 'diabetes'])->assertOk();

        Http::assertSent(fn ($r) => str_contains($r->url(), '/extract') && $r->data()['use_stats_ordering'] === false);
    }

    public function test_use_collection_filter_sent_when_flag_active(): void
    {
        Feature::activateForEveryone('query-builder-use-collections-in-search');

        Http::fake([self::NLP_BASE . '/extract*' => Http::response($this->minimalNlpResponse, 200)]);

        $this->postJson(self::BASE_URL, ['query' => 'diabetes'])->assertOk();

        Http::assertSent(fn ($r) => str_contains($r->url(), '/extract') && $r->data()['use_collection_filter'] === true);
    }

    public function test_collection_ids_forwarded_to_nlp(): void
    {
        Http::fake([self::NLP_BASE . '/extract*' => Http::response($this->minimalNlpResponse, 200)]);

        $this->postJson(self::BASE_URL, [
            'query' => 'diabetes',
            'collection_ids' => ['col-abc', 'col-def'],
        ])->assertOk();

        Http::assertSent(fn ($r) => str_contains($r->url(), '/extract') && $r->data()['collection_ids'] === ['col-abc', 'col-def']);
    }

    public function test_parse_returns_422_for_invalid_collection_ids(): void
    {
        $this->postJson(self::BASE_URL, [
            'query' => 'diabetes',
            'collection_ids' => 'not-an-array',
        ])->assertUnprocessable();
    }
}
