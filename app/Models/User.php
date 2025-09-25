<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Hdruk\ClaimsAccessControl\Traits\HasScopedClaims;

class User extends Authenticatable implements OAuthenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory;
    use Notifiable;
    use HasApiTokens;
    use HasRoles;
    use HasScopedClaims;

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
}
