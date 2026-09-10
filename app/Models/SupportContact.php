<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportContact extends Model
{
    protected $fillable = [
        'user_id', 'channel', 'external_id', 'phone', 'display_name', 'locale',
        'daily_limit_override', 'blocked',
    ];

    protected $casts = [
        'blocked' => 'boolean',
        'daily_limit_override' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(SupportConversation::class, 'contact_id');
    }

    public function dailyUsages(): HasMany
    {
        return $this->hasMany(SupportDailyUsage::class, 'contact_id');
    }
}
