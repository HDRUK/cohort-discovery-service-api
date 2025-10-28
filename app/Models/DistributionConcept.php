<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DistributionConcept extends Model
{
    protected $table = 'distribution_concepts';

    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'distribution_id',
        'collection_id',
        'task_id',
        'distribution_name',
        'category',
        'description',
        'concept_id',
        'concept_name',
        'domain_id',
        'vocabulary_id',
        'concept_class_id',
        'standard_concept',
        'concept_code',
        'count',
        'q1',
        'q3',
        'min',
        'max',
        'mean',
        'median',
        'created_at',
        'updated_at',        
    ];
}
