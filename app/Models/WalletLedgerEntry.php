<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WalletLedgerEntry extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'wallet_account_id', 'payment_id', 'payout_request_id', 'entry_type',
        'amount', 'idempotency_key', 'metadata', 'created_at',
    ];

    protected $casts = ['amount' => 'float', 'metadata' => 'array', 'created_at' => 'datetime'];
}
