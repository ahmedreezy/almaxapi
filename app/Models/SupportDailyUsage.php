<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportDailyUsage extends Model
{
    protected $fillable = [
        'contact_id', 'usage_date', 'replies_reserved', 'replies_used',
        'input_tokens', 'output_tokens', 'limit_notice_sent',
    ];

    protected $casts = [
        'usage_date' => 'date',
        'limit_notice_sent' => 'boolean',
        'replies_reserved' => 'integer',
        'replies_used' => 'integer',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(SupportContact::class, 'contact_id');
    }
}
