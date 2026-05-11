<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Group extends Model
{
    protected $table = 'groups';

    protected $fillable = [
        'name', 'odds_type', 'plan_type', 'price', 'betslip_link', 'betslip_code',
    ];

    protected $casts = [
        'price' => 'float',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Duration in seconds for this group's plan type.
     */
    public function durationSeconds(): int
    {
        return $this->plan_type === 'weekly' ? 7 * 24 * 3600 : 24 * 3600;
    }
}
