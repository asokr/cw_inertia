<?php

namespace App\Models;

use App\Models\Subscribers\SubscribersPlans;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Coupon extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'limit',
        'type',
        'value',
        'start_date',
        'end_date',
    ];

    protected function startDate(): Attribute
    {
        return Attribute::make(
            // get: fn($value) => Carbon::parse($value)->format('d.m.Y H:i'),
            set: fn($value) => Carbon::parse($value) ?? Carbon::now(),
        );
    }
    protected function endDate(): Attribute
    {
        return Attribute::make(
            // get: fn($value) => Carbon::parse($value)->format('d.m.Y H:i'),
            set: fn($value) => Carbon::parse($value) ?? Carbon::now()->addYears(5),
        );
    }

    public function getCouponPlan()
    {
        return SubscribersPlans::find($this->value);
    }
}
