<?php

namespace App\Models\Subscribers\Oz;

use App\Casts\EncryptCast;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OzCabinet extends Model
{
    protected $table = 'oz_cabinets';

    protected $fillable = [
        'user_id',
        'name',
        'client_id',
        'apikey',
        'performance_client_id',
        'performance_client_secret',
        'last_sync_error',
    ];

    protected $hidden = [
        'apikey',
        'performance_client_secret',
    ];

    protected $casts = [
        'apikey' => EncryptCast::class,
        'performance_client_secret' => EncryptCast::class,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function getCreatedAtAttribute($value): string
    {
        return Carbon::parse($value)
            ->setTimezone('Europe/Moscow')
            ->format('d.m.Y H:i');
    }

    public function getUpdatedAtAttribute($value): string
    {
        return Carbon::parse($value)
            ->setTimezone('Europe/Moscow')
            ->format('d.m.Y H:i');
    }
}
