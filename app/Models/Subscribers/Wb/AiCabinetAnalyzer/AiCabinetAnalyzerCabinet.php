<?php

namespace App\Models\Subscribers\Wb\AiCabinetAnalyzer;

use App\Casts\EncryptCast;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiCabinetAnalyzerCabinet extends Model
{
    protected $table = 'wb_ai_cabinet_analyzer_cabinets';

    protected $fillable = [
        'user_id',
        'name',
        'apikey',
    ];

    protected $casts = [
        'apikey' => EncryptCast::class,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(AiCabinetAnalyzerReport::class, 'cabinet_id');
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
