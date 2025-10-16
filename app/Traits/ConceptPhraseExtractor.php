<?php

namespace App\Traits;

trait ConceptPhraseExtractor
{
    protected array $stopPhrases = [
        'people who', 'patients who', 'subjects who',
        'individuals who', 'persons who',
        'with', 'who', 'that', 'had', 'having',
        'record of', 'history of', 'status of', 'diagnosed with'
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
            '/^(received|observed|measured|diagnosed|tested|found|showed|recorded|detected|administered|given|took|taking|had|has|have|carried|reported|performed|evaluated|collected|assessed|noted|monitored|identified|detected|appeared|presented|described|indicated)( with| for| of)?\s*/i',
            '',
            $term
        );

        $term = preg_replace('/[\(\)]/', '', $term);
        $term = str_replace(',', '', $term);

        return ucfirst(trim($term));
    }
}
