<?php

namespace App\Traits;

trait ConceptPhraseExtractor
{
    protected array $stopPhrases = [
        'people who', 'patients who', 'subjects who',
        'individuals who', 'persons who',
        'with', 'who', 'that', 'had', 'having'
    ];

    protected function extractConceptPhrase(string $term): string
    {
        $term = strtolower(trim($term));

        foreach ($this->stopPhrases as $stop) {
            if (str_starts_with($term, $stop)) {
                $term = trim(substr($term, strlen($stop)));
            }
        }

        $term = preg_replace(
            '/^(received|observed|measured|diagnosed)( with| for)?\s*/',
            '',
            $term
        );

        $term = preg_replace('/[\(\)]/', '', $term);
        $term = str_replace(',', '', $term);

        return ucfirst(trim($term));
    }
}
