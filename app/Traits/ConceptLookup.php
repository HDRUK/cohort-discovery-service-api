<?php

namespace App\Traits;

use DB;

use App\Utils\VerbCategoryMapper;
use App\Models\Omop\Concept;

trait ConceptLookup
{
    use ConceptPhraseExtractor;

    protected ?VerbCategoryMapper $mapper = null;

    /**
     * Looks up a concept by term, returning its concept_id, description, category, and children.
     *
     * @param string $term The search term for the concept.
     * @return array{
     *     concept_id: int|null,
     *     description: string,
     *     category: string|null,
     *     children: array<int, array{concept_id: int, description: string, category: string}>
     * }
     */
    protected function lookupConcept(string $term): array
    {
        $cleanedTerm = $this->extractConceptPhrase($term);
        $concept = $this->findConcept($cleanedTerm);

        if (!$concept) {
            return [
                'concept_id' => null,
                'description' => ucfirst($cleanedTerm),
                'category' => $this->getMapper()->inferCategory($term),
                'children' => [],
            ];
        }

        return [
            'concept_id' => $concept->concept_id ?? null,
            'description' => $concept->concept_name ?? $term,
            // Originally this was inferred earlier, but now used as fallback in case
            // domain_id is invalid/null.
            'category' => $concept->domain_id ?? $this->getMapper()->inferCategory($term),
            'children' => isset($concept->ancestors) ? $concept->ancestors->map(function ($ancestor) {
                return [
                    'concept_id' => $ancestor->concept_id,
                    'description' => $ancestor->concept_name,
                    'category' => $ancestor->domain_id ?? 'Unknown',
                ];
            })->toArray()
            : [],
        ];
    }

    /**
     * Finds a concept in the OMOP database by term using full-text search.
     *
     * This function requires FullText index on concept.concept_name. For
     * more information see: App\Console\Commands\AddFullTextIndexToOmopConcepts.php
     *
     * @param string $term The term to search for.
     * @return Concept|null The found concept or null.
     */
    protected function findConcept(string $term): ?Concept
    {
        $results = DB::select("
            SELECT
                concept_id,
                concept_name,
                domain_id
            FROM
                distribution_concepts
            WHERE
                concept_name LIKE ?
            LIMIT 10",
            [
               '%' . $term . '%',
            ]);

        if (empty($results)) {
            return null;
        }

        usort($results, fn ($a, $b) => 
            levenshtein(strtolower($a->concept_name), strtolower($term))
            <=> levenshtein(strtolower($b->concept_name), strtolower($term))
        );

        return new Concept((array)$results[0]); // returns the best match
    }

    /**
     * Gets (and caches) the VerbCategoryMapper instance.
     *
     * @return VerbCategoryMapper
     */
    protected function getMapper(): VerbCategoryMapper
    {
        if ($this->mapper === null) {
            $this->mapper = new VerbCategoryMapper();
        }
        return $this->mapper;
    }
}
