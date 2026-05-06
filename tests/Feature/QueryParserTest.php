<?php

namespace Tests\Feature;

use App\Services\NLP\NLPConceptExtractor;
use Mockery;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

class QueryParserTest extends BaseTestCase
{
    private const BASE_URL = '/api/v1/parse-query';


    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();

        $mock = Mockery::mock(NLPConceptExtractor::class);

        $mock->shouldReceive('extract')
            ->andReturnUsing(fn (string $phrase) => $this->fakeNlpResponse($phrase));

        $this->app->instance(NLPConceptExtractor::class, $mock);
    }

    private function fakeNlpResponse(string $phrase): array
    {


        $map = [
            'men' => ['MALE', 8507, 'Gender'],
            'male' => ['MALE', 8507, 'Gender'],
            'females' => ['FEMALE', 8532, 'Gender'],
            'female' => ['FEMALE', 8532, 'Gender'],
            'cancer' => ['Cancer', 1, 'Condition'],
            'covid' => ['COVID-19', 2, 'Condition'],
            'and tested positive for covid-19' => ['COVID-19', 22, 'Observation'],
            'diabetes' => ['Diabetes mellitus', 3, 'Condition'],
            'smokers' => ['Smoker', 4, 'Observation'],
            'pfizer' => ['Pfizer vaccine', 200, 'Drug'],
            'moderna' => ['Moderna vaccine', 201, 'Drug'],
        ];

        $key = strtolower(trim($phrase));

        $tokens = preg_split(
            '/\s+(?:with|and|having|who have|that have)\s+/i',
            $key
        );

        $entities = [];

        foreach ($tokens as $token) {
            $token = trim($token);

            if ($token === '' || ! isset($map[$token])) {
                continue;
            }

            [$name, $conceptId, $category] = $map[$token];

            $entities[] = [
                'text' => $token,
                'label' => $category,
                'start' => 0,
                'end' => strlen($token),
                'negated' => false,
                'attributes' => [
                    'concept_id' => $conceptId,
                    'concept_name' => $name,
                    'description' => $name,
                    'domain_id' => $category,
                    'ncollections' => 1,
                    'all_synthetic' => 0,
                    'match_score' => 100,
                    'tokens' => [$token],
                    'phrase_tokens' => [$token],
                    'negates' => false,
                ],
                'age_constraints' => [],
                'time_constraints' => [],
            ];
        }

        return [
            'entities' => $entities,
            'groups' => [],
            'warnings' => [],
            'age_constraints' => [],
            'time_constraints' => [],
        ];
    }

    private function fakeEntity(string $text, string $name, int $conceptId, string $category): array
    {
        return [
            'text' => $text,
            'attributes' => [
                'concept_id' => $conceptId,
                'concept_name' => $name,
                'description' => $name,
                'domain_id' => $category,
                'ncollections' => 1,
                'all_synthetic' => 0,
                'match_score' => 100,
                'tokens' => [$text],
                'phrase_tokens' => [$text],
            ],
            'age_constraints' => [],
            'time_constraints' => [],
        ];
    }


    public function test_men_with_cancer_or_covid_creates_shared_context_group(): void
    {
        $rules = $this->parseRules('men with cancer or covid');

        $this->assertCount(3, $rules);

        $this->assertConceptNameContains($rules[0], 'male');
        $this->assertCombinator($rules[1], 'and');

        $this->assertArrayHasKey('rules', $rules[2]);

        $group = $rules[2];
        $this->assertIsGroup($group);
        $innerRules = $rules[2]['rules'];

        $this->assertCount(3, $innerRules);

        $this->assertConceptNameContains($innerRules[0], 'cancer');
        $this->assertCombinator($innerRules[1], 'or');
        $this->assertConceptNameContains($innerRules[2], 'covid');
    }


    public function test_men_with_bracketed_cancer_or_covid_creates_same_shape(): void
    {
        $rules = $this->parseRules('men with (cancer or covid)');

        $this->assertCount(3, $rules);

        $this->assertConceptNameContains($rules[0], 'male');
        $this->assertCombinator($rules[1], 'and');

        $this->assertArrayHasKey('rules', $rules[2]);

        $innerRules = $rules[2]['rules'];

        $this->assertCount(3, $innerRules);

        $this->assertConceptNameContains($innerRules[0], 'cancer');
        $this->assertCombinator($innerRules[1], 'or');
        $this->assertConceptNameContains($innerRules[2], 'covid');
    }

    public function test_bracketed_men_with_cancer_or_covid_creates_top_level_or(): void
    {
        $rules = $this->parseRules('(men with cancer) or covid');

        $this->assertCount(3, $rules);
        $this->assertIsGroup($rules[0]);
        $this->assertCombinator($rules[1], 'or');
        $this->assertConceptNameContains($rules[2], 'covid');

        $leftGroupRules = $rules[0]['rules'];

        $this->assertCount(3, $leftGroupRules);

        $this->assertConceptNameContains($leftGroupRules[0], 'male');
        $this->assertCombinator($leftGroupRules[1], 'and');
        $this->assertConceptNameContains($leftGroupRules[2], 'cancer');
    }


    public function test_mixed_shared_context_query_creates_expected_top_level_or_shape(): void
    {
        $rules = $this->parseRules(
            'men with cancer or females with diabetes or cancer'
        );

        $this->assertCount(3, $rules);

        $this->assertIsGroup($rules[0]);
        $this->assertCombinator($rules[1], 'or');
        $this->assertIsGroup($rules[2]);

        $maleGroup = $rules[0]['rules'];

        $this->assertCount(3, $maleGroup);
        $this->assertConceptNameContains($maleGroup[0], 'male');
        $this->assertCombinator($maleGroup[1], 'and');
        $this->assertConceptNameContains($maleGroup[2], 'cancer');

        $femaleGroup = $rules[2]['rules'];

        $this->assertCount(3, $femaleGroup);
        $this->assertConceptNameContains($femaleGroup[0], 'female');
        $this->assertCombinator($femaleGroup[1], 'and');
        $this->assertIsGroup($femaleGroup[2]);

        $innerRules = $femaleGroup[2]['rules'];

        $this->assertCount(3, $innerRules);
        $this->assertConceptNameContains($innerRules[0], 'diabetes');
        $this->assertCombinator($innerRules[1], 'or');
        $this->assertConceptNameContains($innerRules[2], 'cancer');
    }

    public function test_smokers_with_cancer_creates_and_group(): void
    {
        $rules = $this->parseRules('smokers with cancer');

        $this->assertCount(1, $rules);
        $this->assertIsGroup($rules[0]);

        $groupRules = $rules[0]['rules'];

        $this->assertCount(3, $groupRules);

        $this->assertConceptNameContains($groupRules[0], 'smoker');
        $this->assertCombinator($groupRules[1], 'and');
        $this->assertConceptNameContains($groupRules[2], 'cancer');
    }

    private function parseRules(string $query): array
    {
        $response = $this->postJson(self::BASE_URL, [
            'query' => $query,
        ]);

        $response->assertStatus(200);

        $data = $response->json('data');

        if (is_string($data)) {
            $data = json_decode($data, true);
        }

        $this->assertIsArray($data);
        $this->assertArrayHasKey('rules', $data);

        return $data['rules'];
    }

    public function test_adults_with_vaccine_or_vaccine_and_covid_creates_expected_structure(): void
    {
        $rules = $this->parseRules(
            'adults who either had pfizer or moderna and tested positive for covid-19'
        );

        dump($rules);
        $this->assertCount(5, $rules);

        // age >= 18
        $this->assertArrayHasKey('value', $rules[0]);
        $this->assertSame([18, null], $rules[0]['value']);

        // AND
        $this->assertCombinator($rules[1], 'and');

        // pfizer OR moderna
        $this->assertIsGroup($rules[2]);

        $vaccineGroup = $rules[2]['rules'];

        $this->assertCount(3, $vaccineGroup);
        $this->assertConceptNameContains($vaccineGroup[0], 'pfizer');
        $this->assertCombinator($vaccineGroup[1], 'or');
        $this->assertConceptNameContains($vaccineGroup[2], 'moderna');

        // AND
        $this->assertCombinator($rules[3], 'and');

        // covid-19
        $this->assertConceptNameContains($rules[4], 'covid');
    }


    private function assertCombinator(array $node, string $expected): void
    {
        $this->assertSame($expected, $node['combinator'] ?? null);
    }

    private function assertIsGroup(array $node): void
    {
        $this->assertArrayHasKey('rules', $node);
        $this->assertIsArray($node['rules']);
    }

    private function assertConceptNameContains(array $node, string $expected): void
    {
        $name = $node['rule']['concept']['name'] ?? null;

        $this->assertIsString($name);
        $this->assertStringContainsStringIgnoringCase($expected, $name);
    }
}
