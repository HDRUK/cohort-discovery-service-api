<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LsoaCentroid extends Model
{
    public $timestamps = false;

    protected $table = 'lsoa_centroids';

    protected $primaryKey = 'lsoa_code';

    public $incrementing = false;

    protected $keyType = 'string';
}
