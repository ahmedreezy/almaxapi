<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AlmaxPrediction extends Model
{
    protected $table = 'almax_predictions';
    public $timestamps = false;
    const CREATED_AT = 'created_at';

    protected $fillable = [
        'home', 'away', 'competition', 'kickoff',
        'tip', 'odds', 'result',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
