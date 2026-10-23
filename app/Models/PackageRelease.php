<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PackageRelease extends Model
{
    protected $fillable = [
        'public_id', 'group_id', 'parent_release_id', 'type', 'betslip_link',
        'betslip_code', 'published_at', 'cutoff_at', 'result', 'result_marked_at',
        'result_marked_by',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'cutoff_at' => 'datetime',
        'result_marked_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_release_id');
    }

    public function recovery(): HasMany
    {
        return $this->hasMany(self::class, 'parent_release_id');
    }

    public function recoverySources(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'package_release_recovery_sources',
            'recovery_release_id',
            'source_release_id'
        )->withTimestamps();
    }

    public function sharedRecoveries(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'package_release_recovery_sources',
            'source_release_id',
            'recovery_release_id'
        )->withTimestamps();
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(PackageReleaseEntitlement::class, 'release_id');
    }

    public function resultAdmin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'result_marked_by');
    }
}
