<?php

namespace App\Traits;

trait SearchManager
{
    public function scopeSearchViaRequest($query, ?array $input = null): mixed
    {
        $input = $input ?? request()->all();

        $orGroups = [];
        $andGroups = [];

        foreach ($input as $fieldWithOperator => $searchValues) {
            if (str_ends_with($fieldWithOperator, '__or')) {
                $field = str_replace('__or', '', $fieldWithOperator);
                $logic = 'or';
            } elseif (str_ends_with($fieldWithOperator, '__and')) {
                $field = str_replace('__and', '', $fieldWithOperator);
                $logic = 'and';
            } else {
                $field = $fieldWithOperator;
                $logic = 'or';
            }

            if ($logic === 'or') {
                $orGroups[$field] = $searchValues;
            } else {
                $andGroups[$field] = $searchValues;
            }
        }

        return $query->where(function ($outerQuery) use ($orGroups, $andGroups) {

            foreach ($andGroups as $field => $terms) {
                $outerQuery->where(function ($q) use ($field, $terms) {
                    foreach ($terms as $term) {
                        $q->where($field, 'LIKE', '%' . $term . '%');
                    }
                });
            }

            if (!empty($orGroups)) {
                $outerQuery->where(function ($q) use ($orGroups) {
                    foreach ($orGroups as $field => $terms) {
                        if (is_array($terms)) {
                            foreach ($terms as $term) {
                                $q->orWhere($field, 'LIKE', '%' . $term . '%');
                            }
                        } else {
                            $q->orWhere($field, 'LIKE', '%' . $terms . '%');
                        }
                    }
                });
            }
        });
    }
}
