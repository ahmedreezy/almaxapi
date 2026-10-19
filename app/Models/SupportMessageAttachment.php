<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportMessageAttachment extends Model
{
    protected $fillable = [
        'public_id', 'support_message_id', 'kind', 'disk', 'path', 'original_name',
        'mime_type', 'size_bytes', 'duration_seconds', 'status', 'transcript', 'error',
    ];

    protected $hidden = ['path', 'disk', 'error'];

    protected $casts = [
        'size_bytes' => 'integer',
        'duration_seconds' => 'integer',
    ];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(SupportMessage::class, 'support_message_id');
    }
}
