<?php

namespace App\Models;

use App\Casts\EncryptCast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class WbApiUsageStat extends Model
{
    protected $fillable = [
        'stat_date',
        'api_key_hash',
        'api_key',
        'requests_count',
        'legal_entity',
        'seller_id',
        'legal_entity_synced_at',
    ];

    protected $casts = [
        'stat_date' => 'date',
        'api_key' => EncryptCast::class,
        'legal_entity_synced_at' => 'datetime',
        'requests_count' => 'integer',
    ];

    public function scopeForApiKey(Builder $query, string $apiKey): Builder
    {
        return $query->where('api_key_hash', hash('sha256', $apiKey));
    }

    public function incrementRequest(): void
    {
        $this->requests_count = ($this->requests_count ?? 0) + 1;
    }
}
