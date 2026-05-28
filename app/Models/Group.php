<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Group extends Model
{
    protected $table = 'groups';

    protected $fillable = [
        'name', 'odds_type', 'plan_type', 'price', 'betslip_link', 'betslip_code',
        'is_special', 'is_active', 'special_price', 'special_odds', 'subscription_deadline',
        'photo_url',
    ];

    protected $casts = [
        'price'         => 'float',
        'special_price' => 'float',
        'is_special'    => 'boolean',
        'is_active'     => 'boolean',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Duration in seconds for this group's plan type.
     * monthly = 30 days, weekly = 7 days, special = 7 days, daily = 1 day.
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

    /**
     * The price to charge: uses special_price for special groups when set,
     * falls back to the base price.
     */
    public function effectivePrice(): float
    {
        if ($this->is_special && $this->special_price !== null) {
            return (float) $this->special_price;
        }

        return (float) $this->price;
    }

    /**
     * Whether the current server time is at or past today's subscription deadline.
     * Returns false if no deadline is set (always open).
     */
    public function isPastDeadline(): bool
    {
        return self::isDeadlineClosed($this->subscription_deadline);
    }

    public static function deadlineAt(?string $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = trim($value);

        if (preg_match('/^\d{2}:\d{2}$/', $value)) {
            return now()->startOfDay()->setTimeFromTimeString($value);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value)) {
            return Carbon::createFromFormat('Y-m-d\TH:i', $value);
        }

        return null;
    }

    public static function isDeadlineClosed(?string $value): bool
    {
        $deadline = self::deadlineAt($value);
        if (! $deadline) {
            return false;
        }

        return now()->gt($deadline);
    }

    public static function isDeadlinePastOrCurrent(?string $value): bool
    {
        $deadline = self::deadlineAt($value);
        if (! $deadline) {
            return false;
        }

        return now()->gte($deadline);
    }

    /**
     * Whether this group is visible/purchasable by end-users.
     * Regular groups: must be active.
     * Special groups: must be active AND have a special_price set today.
     */
    public function isPubliclyVisible(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->is_special) {
            return $this->special_price !== null;
        }

        return true;
    }
}
