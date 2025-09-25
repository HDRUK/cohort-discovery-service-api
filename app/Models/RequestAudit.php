<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RequestAudit extends Model
{
    public $timestamps = true;

    protected $fillable = [
        'user_id',
        'method',
        'uri',
        'status',
        'ip_address',
        'user_agent',
        'payload',
    ];
}
