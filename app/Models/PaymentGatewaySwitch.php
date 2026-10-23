<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentGatewaySwitch extends Model
{
    public $timestamps = false;

    protected $fillable = ['from_provider', 'to_provider', 'changed_by', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];
}
