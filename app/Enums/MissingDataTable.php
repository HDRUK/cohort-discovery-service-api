<?php

namespace App\Enums;

/**
 * OMOP tables that a collection may not expose. The backing value matches the
 * concept `category` string produced by the query builder (equivalent to the
 * OMOP domain_id), so a leaf rule can be mapped straight to a missing-table
 * reason.
 */
enum MissingDataTable: string
{
    case Location = 'Location';
    case Death = 'Death';

    public function reason(): string
    {
        return $this->value . ' data table missing';
    }
}
