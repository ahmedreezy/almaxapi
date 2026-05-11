<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory;

    protected $table = "users";
    public $timestamps = false;
    const CREATED_AT = "created_at";

    protected $fillable = ["username", "phone", "password_hash"];
    protected $hidden = ["password_hash"];
    protected $casts = ['created_at' => 'datetime'];

    public function getAuthPassword(): string
    {
        return $this->password_hash ?? "";
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function statusChecks(): HasMany
    {
        return $this->hasMany(StatusCheck::class);
    }
}
