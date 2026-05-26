<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FootballTip extends Model
{
    protected $table = 'football_tips';
    public $timestamps = false;
    const CREATED_AT = 'created_at';

    protected $fillable = [
        'home', 'away', 'competition', 'kickoff',
        'win_prob', 'kit_color', 'kit_number',
        'prediction', 'accent', 'image_url', 'caption',
        'group_id',
    ];

    protected $casts = [
        'win_prob'   => 'integer',
        'created_at' => 'datetime',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }
}
