<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

class User extends Authenticatable implements OAuthenticatable
{
    public const CLIENT_TOKEN_SCOPES = [
        'cohorts:view' => 'View queries',
        'cohorts:create' => 'Create new queries',
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

    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory;
    use Notifiable;
    use HasApiTokens;

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
}
