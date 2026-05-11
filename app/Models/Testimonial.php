<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Testimonial extends Model
{
    protected $table = 'testimonials';
    public $timestamps = false;
    const CREATED_AT = 'created_at';

    protected $fillable = ['caption', 'member_name', 'image_url'];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
