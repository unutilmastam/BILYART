<?php

namespace App\Domain\Users\Models;

use App\Domain\Branches\Models\Branch;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Users\Enums\Role;
use App\Support\Concerns\HasPublicId;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Human user. tenant_id is NULL only for SUPER_ADMIN (DB CHECK).
 * Authentication resolves users through TenantAgnosticUserProvider (the
 * tenant is derived *from* the user); all other reads are tenant-scoped.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property Role $role
 */
#[UseFactory(UserFactory::class)]
class User extends Authenticatable
{
    use BelongsToTenant, HasFactory, HasPublicId;

    protected bool $tenantNullable = true;

    protected $fillable = ['name', 'login', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected $attributes = [
        'is_active' => true,
        'failed_logins' => 0,
    ];

    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'password' => 'hashed',
            'is_active' => 'boolean',
            'failed_logins' => 'integer',
            'locked_until' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
        ];
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === Role::SUPER_ADMIN;
    }

    /** Optional restriction of managers/operators to some branches (empty = all). @return BelongsToMany<Branch, $this> */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'user_branch_access')->withPivot('tenant_id');
    }
}
