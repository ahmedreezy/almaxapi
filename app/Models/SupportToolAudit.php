<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportToolAudit extends Model
{
    protected $fillable = [
        'conversation_id', 'message_id', 'tool_name', 'arguments', 'result', 'successful',
    ];

    protected $casts = [
        'arguments' => 'array',
        'result' => 'array',
        'successful' => 'boolean',
    ];
}
