<?php

namespace App\Traits;

use App\Utils\VerbCategoryMapper;

use App\Models\Omop\Concept;

trait ConceptLookup
{
    use ConceptPhraseExtractor;

    protected ?VerbCategoryMapper $mapper = null;

    protected function lookupConcept(string $term): array
    {
        $cleanedTerm = $this->extractConceptPhrase($term);
        dd($cleanedTerm);

        $concept = Concept::with('ancestors')
            ->where('concept_name', 'LIKE', "%$cleanedTerm%")->first();

        $category = $this->getMapper()->inferCategory($term);

        return [
            'concept_id' => $concept->concept_id ?? null,
            'description' => $concept->concept_name ?? $term,
            'category' => $category,
            'children' => $concept->ancestors?->map(function ($ancestor) {
                return [
                    'concept_id' => $ancestor->concept_id,
                    'description' => $ancestor->concept_name,
                    'category' => $ancestor->domain_id ?? 'Unknown',
                ];
            })->toArray(),
        ];
    }

    protected function getMapper(): VerbCategoryMapper
    {
        if ($this->mapper === null) {
            $this->mapper = new VerbCategoryMapper();
        }
        return $this->mapper;
    }
}