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
