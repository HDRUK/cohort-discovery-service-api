<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
    protected $fillable = [
        'collection_id',
        'task_id',
        'category',
        'name',
        'description',
        'count',
        'q1',
        'q3',
        'min',
        'max',
        'mean',
        'median',
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
