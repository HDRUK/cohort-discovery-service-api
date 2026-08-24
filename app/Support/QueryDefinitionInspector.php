<?php

namespace App\Support;

class QueryDefinitionInspector
{
    /**
     * Walk a query definition tree and return the unique set of OMOP concept
     * category strings used across all leaf rules. The category is equivalent
     * to the OMOP domain_id (e.g. 'Drug', 'Condition', 'Location', 'Death').
     *
     * @param  array<string, mixed>  $definition
     * @return array<int, string>
     */
    public function categoriesUsed(array $definition): array
    {
        $categories = [];
        $this->collect($definition, $categories);

        return array_values(array_unique($categories));
    }

    /**
     * Whether the demographics block carries a geo-radius location filter, i.e.
     * a `location` object of the shape {lat, lon, radius} with numeric values.
     * This mirrors BunnyQueryContext::makeGeoRadiusRule — it is the only
     * location shape that actually emits a rule against the location table, so
     * it is the only one that requires the collection to expose that table.
     * The legacy region-code array shape emits no rule and is ignored here.
     *
     * @param  array<string, mixed>  $definition
     */
    public function usesDemographicLocation(array $definition): bool
    {
        $location = $definition['demographics']['location'] ?? null;
        if (! is_array($location)) {
            return false;
        }

        return is_numeric($location['lat'] ?? null)
            && is_numeric($location['lon'] ?? null)
            && is_numeric($location['radius'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<int, string>  $categories
     */
    private function collect(array $node, array &$categories): void
    {
        // Group node: recurse into its children.
        if (isset($node['rules']) && is_array($node['rules'])) {
            foreach ($node['rules'] as $child) {
                if (is_array($child)) {
                    $this->collect($child, $categories);
                }
            }

            return;
        }

        // Leaf node: pull the concept(s) and record each category.
        $concept = $node['rule']['concept'] ?? null;
        if (! is_array($concept)) {
            return;
        }

        // A leaf may carry a single concept or a list of concepts.
        $concepts = array_is_list($concept) ? $concept : [$concept];
        foreach ($concepts as $single) {
            if (is_array($single) && isset($single['category']) && is_string($single['category'])) {
                $categories[] = $single['category'];
            }
        }
    }
}
