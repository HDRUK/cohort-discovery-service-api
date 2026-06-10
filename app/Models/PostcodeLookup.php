<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PostcodeLookup extends Model
{
    public $timestamps = false;

    protected $table = 'postcode_lookup';

    protected $primaryKey = 'postcode';

    public $incrementing = false;

    protected $keyType = 'string';
}
