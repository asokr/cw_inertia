<?php

namespace App\Models\Subscribers\Wb\Profitability;

use Carbon\Carbon;
use App\Models\User;
use App\Casts\EncryptCast;
use Illuminate\Database\Eloquent\Model;

class ProfitabilityCabinet extends Model
{
    protected $table = 'wb_profitability_cabinets';
    protected $fillable = [
        'user_id',
        'name',
        'apikey',
        'is_migrated',
        'migrated_at',
        'wb_cabinet_id',
    ];
    protected $casts = [
        'apikey' => EncryptCast::class,
        'is_migrated' => 'boolean',
        'migrated_at' => 'datetime',
    ];

    public function user()
    {
        return $this->hasOne(User::class, 'id', 'user_id');
    }

    public function getCreatedAtAttribute($value)
    {
        return Carbon::parse($value)->setTimezone('Europe/Moscow')->format('d.m.Y H:i');
    }
}
