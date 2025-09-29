<?php

namespace App\Models;

use Hdruk\LaravelSearchAndFilter\Traits\Search;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Config;

/**
 * @property int $id
 * @property int $collection_id
 * @property int $task_id
 * @property string $name
 * @property string $description
 * @property number $count
 */
class Distribution extends Model
{
    use Search;

    protected $hidden = ['pivot'];

    public function getConnectionName()
    {
        return Config::get('database.default');
    }

    protected $fillable = [
        'collection_id',
        'task_id',
        'category',
        'name',
        'description',
        'concept_id',
        'count',
        'q1',
        'q3',
        'min',
        'max',
        'mean',
        'median',
    ];

    protected static $searchableColumns = [
        'concept_id',
        'name',
        'description'
    ];


    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
