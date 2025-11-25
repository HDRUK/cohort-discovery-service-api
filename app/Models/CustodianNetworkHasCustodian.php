<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustodianNetworkHasCustodian extends Model
{
    public $table = 'custodian_network_has_custodians';
    public $timestamps = true;

    protected $fillable = [
        'network_id',
        'custodian_id',
    ];
}
