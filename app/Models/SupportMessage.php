<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportMessage extends Model
{
    protected $fillable = [
        'conversation_id', 'direction', 'sender_type', 'body', 'provider_message_id',
        'delivery_status', 'metadata', 'input_tokens', 'output_tokens', 'model', 'sent_at',
    ];

    protected $casts = [
        'body' => 'encrypted',
        'metadata' => 'array',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'sent_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(SupportConversation::class, 'conversation_id');
    }
}
