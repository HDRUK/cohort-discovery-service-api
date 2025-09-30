<?php

namespace App\Models;

use DB;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Hdruk\ClaimsAccessControl\Traits\HasScopedClaims;
use Hdruk\LaravelSearchAndFilter\Traits\Search;
use Illuminate\Database\Eloquent\Builder;

/**
 * @OA\Schema(
 *     schema="User",
 *     type="object",
 *     title="User",
 *     required={"name", "email", "password"},
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="name", type="string", example="Jane Doe"),
 *     @OA\Property(property="email", type="string", format="email", example="jane@example.com"),
 *     @OA\Property(property="email_verified_at", type="string", format="date-time", example="2025-08-06T12:34:56Z"),
 *     @OA\Property(property="created_at", type="string", format="date-time", example="2025-08-06T12:34:56Z"),
 *     @OA\Property(property="updated_at", type="string", format="date-time", example="2025-08-06T12:34:56Z")
 * )
 */
class User extends Authenticatable implements OAuthenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory;
    use Notifiable;
    use HasApiTokens;
    use HasRoles;
    use HasScopedClaims;
    use Search;

    public const CLIENT_TOKEN_SCOPES = [
        'cohorts:read' => 'View queries',
        'cohorts:create' => 'Create new queries',
        'cohorts:update' => 'Update',
        'cohorts:delete' => 'Delete',
        'cohorts:query' => 'Query counts',
        'concepts:read' => 'Read ontology/vocabulary concepts',
        'users:create' => 'Create user data',
        'users:read' => 'Read user data',
        'users:update' => 'Update user data',
        'users:delete' => 'Delete user data',
        'apis:create' => 'Create operations for open APIs',
        'apis:read' => 'Read operations for open APIs',
        'apis:update' => 'Update operations for open APIs',
        'apis:delete' => 'Delete operations for open APIs',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected static array $searchableColumns = [
        'name',
        'email',
    ];

    protected static array $sortableColumns = [
        'id',
        'name',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function workgroups(): BelongsToMany
    {
        return $this->belongsToMany(
            Workgroup::class,
            'user_has_workgroups'
        )->using(UserHasWorkgroup::class);
    }

    public function scopeWithStatus(Builder $query): Builder
    {
        return $query->addSelect('users.*')
            ->addSelect([
            DB::raw('
                CASE
                    WHEN EXISTS (
                        SELECT 1
                        FROM queries
                        WHERE queries.user_id = users.id
                    )
                    THEN 0
                    ELSE 1
                END AS new_user_status
            ')
        ]);
        // return $query->selectRaw("users.*, CASE WHEN COUNT(queries.id) > 0 THEN 0 ELSE 1 END AS new_user_status")
        //     ->leftJoin('queries', 'queries.user_id', '=', 'users.id')
        //     ->groupBy('users.id', 'queries.id');
    }
}
