<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CollectionActivityLog extends Model
{
    public $table = 'collection_activity_logs';
    public $timestamps = true;


    protected $fillable = [
        'created_at',
        'updated_at',
        'collection_id',
        'task_type',
    ];
}
