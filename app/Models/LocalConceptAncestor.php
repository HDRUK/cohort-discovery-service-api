<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LocalConceptAncestor extends Model
{
    protected $table = 'concept_ancestors';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'parent_concept_id',
        'child_concept_id',
    ];
}
