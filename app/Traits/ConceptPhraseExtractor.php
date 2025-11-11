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
        $originalTerm = $term;

        $term = strtolower(trim($term));

        // Normalise punctuation
        $term = preg_replace('/[.,;:]+/', ' ', $term);

        // Remove leading stop phrases
        foreach ($this->stopPhrases as $stop) {
            $pattern = '/^' . preg_quote($stop, '/') . '\b\s+/i';
            $term = preg_replace($pattern, '', $term);
        }

        // foreach ($this->stopPhrases as $stop) {
        //     if (str_starts_with($term, $stop)) {
        //         $term = trim(substr($term, strlen($stop)));
        //     }
        // }

        // // Remove common verb phrases
        // $term = preg_replace(
        //     '/^(received|observed|measured|diagnosed|tested|found|showed|recorded|detected|administered|given|took|taking|had|has|have|carried|reported|performed|evaluated|collected|assessed|noted|monitored|identified|detected|appeared|presented|described|indicated)( with| for| of)?\s*/i',
        //     '',
        //     $term
        // );

        $term = preg_replace(
            '/\b(received|observed|measured|diagnosed|tested|found|showed|recorded|detected|administered|given|took|taking|had|has|have|carried|reported|performed|evaluated|collected|assessed|noted|monitored|identified|appeared|presented|described|indicated)(\s(with|for|of|as))?\b/i',
            '',
            $term
        );

        $term = preg_replace('/[\(\)]/', '', $term);
        // $term = str_replace(',', '', $term);
        $term = preg_replace('/\s+/', ' ', $term);

        // Secondary fallback in case the cleaned term is too short or meaningless
        if (strlen($term) < 3) {
            $term = $originalTerm;
        }

        return ucfirst(trim($term));
    }
}
