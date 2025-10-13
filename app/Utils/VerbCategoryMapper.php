<?php

namespace App\Utils;

class VerbCategoryMapper
{
    protected array $verbMap = [
        // Drug administration
        'received'      => 'Drug',
        'administered'  => 'Drug',
        'given'         => 'Drug',
        'vaccinated'    => 'Drug',
        'prescribed'    => 'Drug',
        'dispensed'     => 'Drug',
        'started'       => 'Drug',
        'initiated'     => 'Drug',
        'continued'     => 'Drug',
        'stopped'       => 'Drug',
        'ceased'        => 'Drug',
        'discontinued'  => 'Drug',
        'took'          => 'Drug',
        'taking'        => 'Drug',
        'dosed'         => 'Drug',
        'treated'       => 'Drug',

        // Measurements and tests
        'measured'      => 'Measurement',
        'tested'        => 'Measurement',
        'assessed'      => 'Measurement',
        'recorded'      => 'Measurement',
        'checked'       => 'Measurement',
        'monitored'     => 'Measurement',
        'evaluated'     => 'Measurement',
        'collected'     => 'Measurement',
        'sampled'       => 'Measurement',
        'taken'         => 'Measurement', // e.g. "blood taken"
        'performed'     => 'Measurement',

        // Observations and findings
        'observed'      => 'Observation',
        'noted'         => 'Observation',
        'seen'          => 'Observation',
        'found'         => 'Observation',
        'identified'    => 'Observation',
        'detected'      => 'Observation',
        'appeared'      => 'Observation',
        'presented'     => 'Observation',
        'reported'      => 'Observation',
        'described'     => 'Observation',
        'showed'        => 'Observation', // e.g. "CT showed a lesion"

        // Diagnoses and conditions
        'diagnosed'     => 'Condition',
        'suffers'       => 'Condition',
        'has'           => 'Condition',
        'with'          => 'Condition',
        'developed'     => 'Condition',
        'experiencing'  => 'Condition',
        'presented'     => 'Condition',
        'complained'    => 'Condition',
        'reports'       => 'Condition',
        'found'         => 'Condition',
        'positive'      => 'Condition', // e.g. “tested positive for…”
        'negative'      => 'Condition',
        'indicates'     => 'Condition',
    ];

    public function inferCategory(string $phrase): string
    {
        $lower = strtolower($phrase);

        foreach ($this->verbMap as $verb => $category) {
            if (str_contains($lower, $verb)) {
                return $category;
            }
        }

        return 'Unknown';
    }

    public function addMapping(string $verb, string $category): void
    {
        // Allows dynamic addition of verb-category mappings
        $this->verbMap[strtolower($verb)] = $category;
    }

    public function all(): array
    {
        return $this->verbMap;
    }
}