<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class WorkgroupHasCollection extends Pivot
{
    protected $table = 'workgroup_has_collection';
    public $timestamps = false;

    protected $fillable = [
        'workgroup_id',
        'collection_id',
    ];
}
