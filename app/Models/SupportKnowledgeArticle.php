<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportKnowledgeArticle extends Model
{
    protected $fillable = [
        'public_id', 'title', 'question', 'answer', 'keywords', 'locale', 'status',
        'version', 'created_by', 'updated_by', 'published_at',
    ];

    protected $casts = [
        'keywords' => 'array',
        'published_at' => 'datetime',
    ];
}
