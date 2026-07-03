<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExtraLimits extends Model
{
    use HasFactory;

    protected $fillable = [
        'price',
        'limit_name',
        'quantity',
        'order'
    ];

    protected $casts = [
        'price' => 'float',
    ];

}
