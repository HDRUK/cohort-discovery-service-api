<?php

namespace App\Models;

use App\Services\QueryContext\QueryContextType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property string $name
 * @property string $pid
 * @property QueryContextType $type
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Task[] $tasks
 */
class Collection extends Model
{
    use HasFactory;
    protected $fillable = [
        'name',
        'pid',
        'type',
    ];

    protected $casts = [
        'type' => QueryContextType::class,
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];


    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function demographics(): HasMany
    {
        $sub = Distribution::select(DB::raw('MAX(id) as id'))
            ->where('category', 'DEMOGRAPHICS')
            ->groupBy('name');

        return $this->hasMany(Distribution::class)
            ->whereIn('id', $sub);
    }

    public function codes(): HasMany
    {
        $sub = Distribution::select(DB::raw('MAX(id) as id'))
            ->where('category', '!=', 'DEMOGRAPHICS')
            ->groupBy('name');

        return $this->hasMany(Distribution::class)
            ->whereIn('id', $sub);
    }


    public function size(): HasOne
    {
        return $this->hasOne(Distribution::class)
            ->where([
                "category" => "DEMOGRAPHICS",
                "name" => "SEX"
            ])
            ->latest('created_at');
    }

    public function host(): BelongsToMany
    {
        return $this->belongsToMany(
            CollectionHost::class,
            'collection_host_has_collections',
            'collection_id',
            'collection_host_id'
        )->limit(1);
    }
}
