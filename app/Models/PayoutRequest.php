<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayoutRequest extends Model
{
    protected $fillable = [
        'reference', 'client_token', 'wallet_account_id', 'beneficiary', 'provider', 'amount',
        'currency', 'destination_msisdn', 'status', 'requested_by',
        'provider_request_id', 'transaction_id', 'provider_fee_actual', 'failure_reason',
        'cancelled_at', 'completed_at',
    ];

    protected $casts = [
        'amount' => 'float', 'provider_fee_actual' => 'float',
        'cancelled_at' => 'datetime', 'completed_at' => 'datetime',
        'created_at' => 'datetime', 'updated_at' => 'datetime',
    ];

    public function walletAccount(): BelongsTo
    {
        return $this->belongsTo(WalletAccount::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'requested_by');
    }
}
