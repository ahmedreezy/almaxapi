<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Single-row configuration for the free weekly odd.
 * id is always 1. Uses updateOrCreate to maintain that invariant.
 */
class FreeOdd2 extends Model
{
    protected $table = 'free_odd2';
    public $timestamps = false;
    const UPDATED_AT = 'updated_at';

    protected $fillable = [
        'team_a', 'team_b', 'pick', 'odd',
        'time', 'competition', 'image_url', 'caption',
    ];

    protected $casts = [
        'updated_at' => 'datetime',
    ];

    /**
     * Always return (and create if missing) the single row with id=1.
     */
    public static function instance(): static
    {
        return static::firstOrCreate(['id' => 1], [
            'team_a'      => 'Team A',
            'team_b'      => 'Team B',
            'pick'        => 'Over 2.5 Goals',
            'odd'         => '2.00',
            'time'        => '20:45',
            'competition' => 'Premier League',
            'image_url'   => '',
            'caption'     => '',
        ]);
    }
}
