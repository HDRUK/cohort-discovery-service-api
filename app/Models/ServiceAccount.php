<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServiceAccount extends Model
{
    /** @use HasFactory<\Database\Factories\ServiceAccountFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'client_id',
        'client_secret',
    ];

    protected $hidden = [
        'client_secret',
    ];

    protected $casts = [
        'client_secret' => 'hashed',
    ];
}
