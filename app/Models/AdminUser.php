<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Laravel\Sanctum\HasApiTokens;

/**
 * Represents an admin user. Stored in the admin_users table.
 *
 * Authentication: username + password_hash
 * Tokens: Sanctum — issued with ['role:admin'] ability, expire in 12 hours.
 *
 * This is intentionally a SEPARATE model from User so that admin tokens
 * and user tokens are never interchangeable. EnsureAdminToken middleware
 * checks tokenable_type === AdminUser::class.
 */
class AdminUser extends Authenticatable
{
    use HasApiTokens, HasFactory;

    protected $table = 'admin_users';

    // No updated_at column in the existing schema
    public $timestamps = false;
    const CREATED_AT = 'created_at';

    protected $fillable = ['username', 'password_hash'];

    protected $hidden = ['password_hash'];

    /**
     * Override to point Laravel's auth helpers at the correct column.
     * The existing schema uses password_hash (bcryptjs $2b$ compatible with PHP $2y$).
     */
    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }
}
