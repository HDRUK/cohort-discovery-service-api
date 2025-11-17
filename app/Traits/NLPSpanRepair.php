<?php

namespace App\Traits;

trait NLPSpanRepair
{
    /**
     * Repair NLP spans that contain combinators (“and”, “or”) or non-medical trailing words.
     */
    protected function repairNlpEntities(array $entities): array
    {
        $cleaned = [];

        foreach ($entities as $originalText => $e) {

            $lowerText = strtolower($e['text']);
            $parts = preg_split('/\s+(and|or)\s+/', $lowerText);

            foreach ($parts as $part) {

                $clean = trim($part);
                if (strlen($clean) < 3) {
                    continue;
                }

                // find exact position of this sub-phrase within original text
                $relative = stripos($lowerText, $clean);

                if ($relative === false) {
                    continue;
                }

                $newStart = $e['start'] + $relative;
                $newEnd   = $newStart + strlen($clean);

                $concept = $this->lookupConceptFromNlp(ucfirst($clean));

                if ($concept && !empty($concept['concept_id'])) {
                    $cleaned[] = [
                        'text'  => $clean,
                        'label' => 'Condition',
                        'start' => $newStart,
                        'end'   => $newEnd,
                        'attributes' => $concept
                    ];
                }
            }
        }

        return $cleaned;
    }

}
