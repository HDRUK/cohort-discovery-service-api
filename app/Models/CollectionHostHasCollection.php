<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CollectionHostHasCollection extends Model
{
    protected $table = 'collection_host_has_collections';

    public $timestamps = false;

    protected $fillable = [
        'collection_host_id',
        'collection_id',
    ];
}