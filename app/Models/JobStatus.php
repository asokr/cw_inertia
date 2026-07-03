<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JobStatus extends Model
{
    protected $fillable = [
        'job_name',
        'data',     // JSON-поле
        'status',
        'error',
    ];

    protected $casts = [
        'data' => 'array',
    ];
}
