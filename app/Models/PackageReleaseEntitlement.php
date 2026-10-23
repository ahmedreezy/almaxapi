<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PackageReleaseEntitlement extends Model
{
    protected $fillable = [
        'release_id', 'source_release_id', 'subscription_id', 'user_id', 'granted_at', 'seen_at',
    ];

    protected $casts = ['granted_at' => 'datetime', 'seen_at' => 'datetime'];

    public function release(): BelongsTo
    {
        return $this->belongsTo(PackageRelease::class, 'release_id');
    }

    public function sourceRelease(): BelongsTo
    {
        return $this->belongsTo(PackageRelease::class, 'source_release_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
