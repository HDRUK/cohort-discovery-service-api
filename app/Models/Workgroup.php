<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Workgroup extends Model
{
    public $timestamps = true;

    protected $fillable = [
        'name',
        'active',
    ];

}
