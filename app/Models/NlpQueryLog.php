<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NlpQueryLog extends Model
{
    public $table = 'nlp_query_logs';

    public $timestamps = true;

    protected $fillable = [
        'query',
        'nlp_extracted',
        'user_id',
    ];
}
