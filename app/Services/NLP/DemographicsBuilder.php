<?php

namespace App\Services\NLP;

use Generator;
use Illuminate\Support\Facades\Log;

/**
 * Builds the demographics block (age band, sex, race, and death) from an NLP /extract
 * response.
 *
 * The NLP service does not build demographics itself — it returns clinical
 * entities with gender concepts mixed in (as ordinary fuzzy matches), plus a
 * separate age_constraints list. This service trawls those for the demographic
 * signal so the API can surface it as a dedicated block.
 */
class DemographicsBuilder
{
    /**
     * OMOP Gender-domain standard concepts, keyed by concept_id.
     * - this is a placeholder
     * - will likely expand out to a controlled list that gets passed
     * - and/or look up from the latest_distributions table
     */
    private const GENDER_CONCEPTS = [8507 => 'Male', 8532 => 'Female'];

    public function build(array $extract): array
    {

        Log::info("Extract: ", $extract);

        return [
            'age' => $this->collapseAge($extract['age_constraints'] ?? []),
            'sex' => $this->collectSex($extract),
            'race' => [], #to-do
            'death' => $this->collectDeath($extract['death_constraints'] ?? null),
        ];
    }

    /**
     * Lowercased text spans that resolved to a gender concept anywhere in the
     * extract. Callers use this to drop demographic spans from the clinical
     * rules (the whole span, not just the gender candidate, since the rest of a
     * gender span's candidates are fuzzy noise).
     *
     * @return array<string, true>
     */
    public function genderTextSpans(array $extract): array
    {
        $spans = [];

        foreach ($this->iterEntities($extract) as $entity) {
            if (! $this->isGenderEntity($entity)) {
                continue;
            }

            $text = strtolower(trim($entity['text'] ?? ''));
            if ($text !== '') {
                $spans[$text] = true;
            }
        }

        return $spans;
    }

    /**
     * @return array<int, array{concept_id: int, name: string, category: string}>
     */
    private function collectSex(array $extract): array
    {
        $sex = [];

        foreach ($this->iterEntities($extract) as $entity) {
            if (! $this->isGenderEntity($entity)) {
                continue;
            }

            $conceptId = $entity['attributes']['concept_id'];
            if (isset($sex[$conceptId])) {
                continue;
            }

            $sex[$conceptId] = [
                'concept_id' => $conceptId,
                'name' => self::GENDER_CONCEPTS[$conceptId],
                'category' => $entity['attributes']['domain_id'] ?? 'Gender',
            ];
        }

        return array_values($sex);
    }

    private function isGenderEntity(array $entity): bool
    {
        $conceptId = $entity['attributes']['concept_id'] ?? null;

        return $conceptId !== null && isset(self::GENDER_CONCEPTS[$conceptId]);
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
            fn($c) => is_array($c) && ($c['scope'] ?? 'query') === 'query'
        );

        $mins = array_filter(array_column($constraints, 'min'), fn($v) => $v !== null);
        $maxs = array_filter(array_column($constraints, 'max'), fn($v) => $v !== null);

        $lo = $mins ? max($mins) : $ageMin;
        $hi = $maxs ? min($maxs) : $ageMax;

        return [max($ageMin, (int) $lo), min($ageMax, (int) $hi)];
    }

    /**
     * Currently, can either be 0 to represent no death recorded,
     * 1 to represent death recorded, or null for "any"
     * 
     * @return ?array
     */
    private function collectDeath(?int $extract): ?array
    {
        try {
            if (is_null($extract)) {
                return null;
            }

            // Log::info("Death extract: " . json_encode($extract));

            return ['value' => $extract];
        } catch (\Exception $e) {
            Log::error('Error in collectDeath: ' . $e->getMessage());
            return null;
        }
    }
}
