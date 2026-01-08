<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class CustodianHasUser extends Pivot
{
    protected $table = 'custodian_has_users';

    public $timestamps = false;
    protected $fillable = [
        'custodian_id',
        'user_id',
    ];
}
