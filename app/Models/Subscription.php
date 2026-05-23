<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

// Group is in the same namespace

class Subscription extends Model
{
    protected $table = 'subscriptions';
    public $timestamps = false;
    const CREATED_AT = 'created_at';

    protected $fillable = [
        'user_id', 'group_id', 'plan_type', 'odds_type', 'payment_method', 'phone',
        'amount', 'status', 'payment_reference', 'rejection_reason',
        'betslip_link', 'betslip_code',
        'started_at', 'expires_at',
    ];

    protected $casts = [
        'amount'     => 'float',
        'started_at' => 'datetime',
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    // ─── Relationships ────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    // ─── Business logic helpers ───────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->status === 'active'
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }

    public function isExpired(): bool
    {
        return $this->status === 'active'
            && $this->expires_at !== null
            && $this->expires_at->isPast();
    }

    /**
     * Duration in seconds for this plan type.
     */
    public function durationSeconds(): int
    {
        return match ($this->plan_type) {
            'monthly' => 30 * 24 * 3600,
            'weekly'  => 7 * 24 * 3600,
            'special' => 7 * 24 * 3600,
            default   => 24 * 3600,
        };
    }
}
