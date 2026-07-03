<?php

namespace App\Models\Subscribers\Wb\PriceCalculation;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PriceCalculationCommissions extends Model
{
    use HasFactory;

    protected $table = 'wb_price_commissions';
    protected $fillable = [
        'subjectID',
        'data',
    ];

    protected function data(): Attribute
    {
        return new Attribute(
            get: fn($value) => json_decode($value, true),
            set: fn($value) => json_encode($value, JSON_UNESCAPED_UNICODE),
        );
    }

}
