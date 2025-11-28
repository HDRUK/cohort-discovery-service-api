<?php

namespace App\Jobs;

use App\Models\Distribution;
use App\Models\LocalConceptAncestor;
use App\Models\Omop\ConceptAncestor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PopulateLocalConceptAncestors implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(): void
    {
        $conceptIds = Distribution::whereNotNull('concept_id')
            ->where('concept_id', '>', 0)
            ->distinct()
            ->pluck('concept_id')
            ->values();

        if ($conceptIds->isEmpty()) {
            return;
        }

        ConceptAncestor::query()
            ->selectRaw('ancestor_concept_id as parent_concept_id, descendant_concept_id as child_concept_id')
            ->whereIn('ancestor_concept_id', $conceptIds)
            ->whereIn('descendant_concept_id', $conceptIds)
            ->whereColumn('ancestor_concept_id', '!=', 'descendant_concept_id')
            ->where('min_levels_of_separation', 1)
            ->orderBy('ancestor_concept_id')
            ->orderBy('descendant_concept_id')
            ->toBase()
            ->chunk(500, function ($rows) {
                $payload = [];
                foreach ($rows as $r) {
                    $payload[] = [
                        'parent_concept_id' => (int) $r->parent_concept_id,
                        'child_concept_id' => (int) $r->child_concept_id,
                    ];
                }
                $payload = collect($payload)
                    ->unique(fn ($r) => $r['parent_concept_id'].'-'.$r['child_concept_id'])
                    ->values()
                    ->all();

                if (! empty($payload)) {
                    LocalConceptAncestor::insertOrIgnore($payload);
                }
            });
    }
}
