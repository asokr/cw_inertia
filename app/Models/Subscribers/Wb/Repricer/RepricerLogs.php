<?php

namespace App\Models\Subscribers\Wb\Repricer;

use App\Models\Subscribers\Wb\WbCabinet;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class RepricerLogs extends Model
{
    use HasFactory;

    protected $table = 'wb_repricer_logs';
    protected $fillable = [
        'cabinet_id',
        'nmID',
        'message',
        'type',
        'strategy'
    ];

    public function cabinet()
    {
        return $this->belongsTo(WbCabinet::class, 'cabinet_id');
    }

    public function getCreatedAtAttribute($value)
    {
        return Carbon::parse($value)->setTimezone('Europe/Moscow')->format('d.m.Y H:i');
    }
}
