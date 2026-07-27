<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExtraLimits extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug',
        'name',
        'price',
        'order',
    ];

    protected $casts = [
        'price' => 'float',
        'order' => 'integer',
    ];
}
