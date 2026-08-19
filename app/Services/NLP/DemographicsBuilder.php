<?php

namespace App\Services\NLP;

use App\Services\TermDirectory\TermDirectoryService;
use Generator;

/**
 * Builds the demographics block (age band, sex, race) from an NLP /extract
 * response.
 *
 * The NLP service does not build demographics itself — it returns clinical
 * entities with gender/race concepts mixed in (as ordinary fuzzy matches),
 * plus a separate age_constraints list. This service trawls those for the
 * demographic signal so the API can surface it as a dedicated block.
 */
class DemographicsBuilder
{
    private const DOMAIN_GENDER = 'Gender';
    private const DOMAIN_RACE = 'Race';

    public function __construct(private TermDirectoryService $termDirectory = new TermDirectoryService())
    {
    }

    public function build(array $extract, array $collectionIds = []): array
    {
        $genderNames = $this->conceptNamesForDomain(self::DOMAIN_GENDER, $collectionIds);
        $raceNames = $this->conceptNamesForDomain(self::DOMAIN_RACE, $collectionIds);

        return [
            'age' => $this->collapseAge($extract['age_constraints'] ?? []),
            'sex' => $this->collectDemographicConcepts($extract, self::DOMAIN_GENDER, $genderNames),
            'race' => $this->collectDemographicConcepts($extract, self::DOMAIN_RACE, $raceNames),
        ];
    }

    /**
     * Lowercased text spans that resolved to a demographic concept (in any of
     * the given domains) anywhere in the extract. Callers use this to drop
     * demographic spans from the clinical rules (the whole span, not just the
     * matched candidate, since the rest of a demographic span's candidates
     * are fuzzy noise).
     *
     * @param  list<int>  $collectionIds
     * @param  list<string>  $domains
     * @return array<string, true>
     */
    public function demographicTextSpans(array $extract, array $collectionIds, array $domains): array
    {
        $spans = [];

        foreach ($domains as $domain) {
            $names = $this->conceptNamesForDomain($domain, $collectionIds);

            foreach ($this->iterEntities($extract) as $entity) {
                $conceptId = $entity['attributes']['concept_id'] ?? null;
                if ($conceptId === null || ! isset($names[$conceptId])) {
                    continue;
                }

                $text = strtolower(trim($entity['text'] ?? ''));
                if ($text !== '') {
                    $spans[$text] = true;
                }
            }
        }

        return $spans;
    }

    /**
     * concept_id => concept_name for the given OMOP domain, scoped to the
     * collections visible to the current user (optionally further narrowed
     * to $collectionIds).
     *
     * @return array<int, string>
     */
    private function conceptNamesForDomain(string $domain, array $collectionIds): array
    {
        return $this->termDirectory
            ->conceptOptionsForDomains([$domain], $collectionIds)
            ->pluck('concept_name', 'concept_id')
            ->all();
    }

    /**
     * @param  array<int, string>  $names  concept_id => concept_name
     * @return array<int, array{concept_id: int, name: string, category: string}>
     */
    private function collectDemographicConcepts(array $extract, string $domain, array $names): array
    {
        $result = [];

        foreach ($this->iterEntities($extract) as $entity) {
            $conceptId = $entity['attributes']['concept_id'] ?? null;
            if ($conceptId === null || ! isset($names[$conceptId]) || isset($result[$conceptId])) {
                continue;
            }

            $result[$conceptId] = [
                'concept_id' => $conceptId,
                'name' => $names[$conceptId],
                'category' => $entity['attributes']['domain_id'] ?? $domain,
            ];
        }

        return array_values($result);
    }

    /**
     * Yields every entity: top-level plus those nested in parenthesised groups
     * and OR-split root groups (and their nested groups).
     *
     * @return Generator<array>
     */
    private function iterEntities(array $extract): Generator
    {
        yield from $extract['entities'] ?? [];

        foreach ($extract['groups'] ?? [] as $group) {
            yield from $group['entities'] ?? [];
        }

        foreach ($extract['root_groups'] ?? [] as $rootGroup) {
            yield from $rootGroup['entities'] ?? [];

            foreach ($rootGroup['groups'] ?? [] as $group) {
                yield from $group['entities'] ?? [];
            }
        }
    }

    /**
     * Collapse query-scope age constraints into a single [lo, hi] band, taking
     * the tightest bound on each side and clamping to the configured
     * demographic age range.
     *
     * @return array{0: int, 1: int}
     */
    private function collapseAge(array $constraints): array
    {
        $ageMin = config('system.demographic_age_min');
        $ageMax = config('system.demographic_age_max');

        $constraints = array_filter(
            $constraints,
            fn ($c) => is_array($c) && ($c['scope'] ?? 'query') === 'query'
        );

        $mins = array_filter(array_column($constraints, 'min'), fn ($v) => $v !== null);
        $maxs = array_filter(array_column($constraints, 'max'), fn ($v) => $v !== null);

        $lo = $mins ? max($mins) : $ageMin;
        $hi = $maxs ? min($maxs) : $ageMax;

        return [max($ageMin, (int) $lo), min($ageMax, (int) $hi)];
    }
}
