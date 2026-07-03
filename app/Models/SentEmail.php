<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class SentEmail extends Model
{
    protected $fillable = [
        'to',
        'subject',
        'body',
        'type',
        'status',
        'error_message',
        'meta'
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function getCreatedAtAttribute($value)
    {
        return Carbon::parse($value)->format('d.m.Y H:i');
    }
}
