<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    public function entitlements(): HasMany
    {
        return $this->hasMany(PackageReleaseEntitlement::class, 'release_id');
    }

    public function resultAdmin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'result_marked_by');
    }
}
