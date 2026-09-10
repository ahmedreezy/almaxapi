<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportConversation extends Model
{
    protected $fillable = [
        'public_id', 'contact_id', 'status', 'mode', 'category', 'sentiment',
        'priority', 'summary', 'assigned_admin_id', 'last_message_at',
        'human_requested_at', 'resolved_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'human_requested_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(SupportContact::class, 'contact_id');
    }

    public function assignedAdmin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'assigned_admin_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class, 'conversation_id');
    }
}
