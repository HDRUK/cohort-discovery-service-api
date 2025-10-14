<?php

namespace App\Traits;

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
        $category = $this->getMapper()->inferCategory($term);

        return [
            'concept_id' => $concept->concept_id ?? null,
            'description' => $concept->concept_name ?? $term,
            'category' => $category,
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
        return Concept::on('omop')
            ->whereRaw(
                'MATCH(concept_name) AGAINST (? IN NATURAL LANGUAGE MODE)',
                [$term]
            )->first();
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
