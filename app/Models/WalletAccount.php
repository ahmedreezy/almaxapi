<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WalletAccount extends Model
{
    protected $fillable = ['beneficiary', 'provider', 'currency'];

    public function entries(): HasMany
    {
        return $this->hasMany(WalletLedgerEntry::class);
    }

    public function payoutRequests(): HasMany
    {
        return $this->hasMany(PayoutRequest::class);
    }
}
