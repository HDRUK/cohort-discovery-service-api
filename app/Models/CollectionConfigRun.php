<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CollectionConfigRun extends Model
{
    public $table = 'collection_config_runs';

    public $timestamps = false;

    protected $fillable = [
        'collection_config_id',
        'query_id',
        'task_id',
        'ran_at',
        'successful',
        'errors',
    ];

    protected $casts = [
        'successful' => 'boolean',
    ];
}
