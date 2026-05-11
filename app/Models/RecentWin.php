<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecentWin extends Model
{
    protected $table = 'recent_wins';
    public $timestamps = false;
    const CREATED_AT = 'created_at';

    protected $fillable = [
        'bet_type', 'date', 'staked', 'returned',
        'odds', 'member_name', 'image_url',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
