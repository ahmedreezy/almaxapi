<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $table = 'payments';
    public $timestamps = false;
    const CREATED_AT = 'created_at';

    protected $fillable = [
        'subscription_id', 'user_id', 'amount',
        'plan_type', 'payment_method', 'phone', 'status',
        'payment_reference', 'transaction_id',
        'agent_commission_amount', 'agent_commission_ratio',
        'agent_commission_status', 'agent_commission_reference',
        'agent_commission_transaction_id', 'agent_commission_recipient',
        'agent_commission_error', 'agent_commission_processed_at',
    ];

    protected $casts = [
        'amount'                        => 'float',
        'agent_commission_amount'       => 'float',
        'agent_commission_ratio'        => 'float',
        'agent_commission_processed_at' => 'datetime',
        'created_at'                    => 'datetime',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
