<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class RegressionTest extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'pid',
        'name',
        'query_id',
        'user_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function ($test) {
            $test->pid = $test->pid ?? (string) Str::uuid();
        });
    }

    public function getValidationRules(string $context): array
    {
        return match (strtolower($context)) {
            'store' => [
                'name' => 'required|string|min:3|max:255',
                'query_definition' => 'required|array',
                'collections' => 'required|array|min:1',
                'collections.*.pid' => 'required|string|exists:collections,pid',
                'collections.*.expected_result' => 'nullable|integer|min:0',
            ],
            'update' => [
                'name' => 'sometimes|string|min:3|max:255',
                'query_definition' => 'sometimes|array',
                'collections' => 'sometimes|array|min:1',
                'collections.*.pid' => 'required_with:collections|string|exists:collections,pid',
                'collections.*.expected_result' => 'nullable|integer|min:0',
            ],
            default => [],
        };
    }

    /** @phpstan-return BelongsTo<Query, $this> */
    public function queryDefinition(): BelongsTo
    {
        return $this->belongsTo(Query::class, 'query_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @phpstan-return BelongsToMany<Collection, $this, RegressionTestHasCollection, 'regressionTestPivot'> */
    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(Collection::class, 'regression_test_collection')
            ->withPivot('expected_result')
            ->using(RegressionTestHasCollection::class)
            ->as('regressionTestPivot');
    }
}
