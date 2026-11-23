<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'name',
    'email',
    'password',
    'role',
    'is_active',
])]
#[Hidden([
    'password',
    'remember_token',
])]
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Available user roles.
     */
    public const ROLE_ADMINISTRATOR = 'administrator';

    public const ROLE_MANAGER = 'manager';

    public const ROLE_STAFF = 'staff';

    public const ROLE_VIEWER = 'viewer';

    /**
     * Available roles.
     */
    public const AVAILABLE_ROLES = [
        self::ROLE_ADMINISTRATOR,
        self::ROLE_MANAGER,
        self::ROLE_STAFF,
        self::ROLE_VIEWER,
    ];

    /**
     * Attribute casting.
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Check whether the account is active.
     */
    public function isActive(): bool
    {
        return $this->is_active === true;
    }

    /**
     * Check whether user is administrator.
     */
    public function isAdministrator(): bool
    {
        return $this->hasRole(self::ROLE_ADMINISTRATOR);
    }

    /**
     * Check whether user has a specific role.
     *
     * @param string|array $roles
     */
    public function hasRole(string|array $roles): bool
    {
        return in_array(
            $this->role,
            (array) $roles,
            true
        );
    }

    /**
     * Check whether user has any of the specified roles.
     */
    public function hasAnyRole(array $roles): bool
    {
        return $this->hasRole($roles);
    }

    /**
     * Check whether user can manage users.
     */
    public function canManageUsers(): bool
    {
        return $this->isAdministrator();
    }
}
