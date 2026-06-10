<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CollectionMetadata extends Model
{
    protected $table = 'collection_metadata';

    protected $fillable = [
        'collection_id',
        'result_file_id',
        'biobank',
        'protocol',
        'os',
        'bclink',
        'datamodel',
        'rounding',
        'threshold',
        'death_filter',
        'supports_death_filter',
        'supports_location_filter',
        'supports_condition',
        'supports_drug',
        'supports_observation',
        'supports_measurement',
        'supports_demographics',
        'location_has_coordinates',
    ];

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    public function resultFile(): BelongsTo
    {
        return $this->belongsTo(ResultFile::class);
    }
}
