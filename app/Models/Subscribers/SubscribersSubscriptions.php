<?php

namespace App\Models\Subscribers;

use Carbon\Carbon;
use App\Models\User;
use App\Models\CouponUsage;
use Illuminate\Support\Facades\Auth;
use App\Models\Subscribers\Subscribers;
use Illuminate\Database\Eloquent\Model;
use App\Models\Subscribers\SubscribersPlans;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SubscribersSubscriptions extends Model
{
    use HasFactory;

    protected $fillable = [
        'subscribers_id',
        'plan_id',
        'limits_plan',
        'extra_limits_plan',
        'limits_month',
        'extra_limits_month',
        'start_date',
        'end_date',
        'status'
    ];

    protected function limitsPlan(): Attribute
    {
        return new Attribute(
            get: fn($value) => json_decode($value, true),
            set: fn($value) => json_encode($value, JSON_UNESCAPED_UNICODE),
        );
    }

    protected function extraLimitsPlan(): Attribute
    {
        return new Attribute(
            get: fn($value) => json_decode($value, true),
            set: fn($value) => json_encode($value, JSON_UNESCAPED_UNICODE),
        );
    }

    protected function limitsMonth(): Attribute
    {
        return new Attribute(
            get: fn($value) => json_decode($value, true),
            set: fn($value) => json_encode($value, JSON_UNESCAPED_UNICODE),
        );
    }

    protected function extraLimitsMonth(): Attribute
    {
        return new Attribute(
            get: fn($value) => json_decode($value, true),
            set: fn($value) => json_encode($value, JSON_UNESCAPED_UNICODE),
        );
    }

    public function getStartDateAttribute($value)
    {
        return Carbon::parse($value)->format('d.m.Y H:i');
    }

    public function getEndDateAttribute($value)
    {
        return Carbon::parse($value)->format('d.m.Y H:i');
    }

    public function plan()
    {
        return $this->hasOne(SubscribersPlans::class, 'id', 'plan_id');
    }

    public function getPlan()
    {
        return SubscribersPlans::where('id', $this->plan_id)->first();
    }

    public function getUser()
    {
        $subscriber = Subscribers::find($this->subscribers_id);
        return User::find($subscriber->user_id);
    }

    public function couponUsage()
    {
        return $this->hasOneThrough(
            CouponUsage::class,
            Subscribers::class,
            'id',        // Foreign key на Subscribers в SubscribersSubscriptions (subscribers_id)
            'user_id',   // Foreign key на CouponUsage (user_id)
            'subscribers_id', // Local key в SubscribersSubscriptions
            'user_id'    // Local key в Subscribers
        );
    }

    public function getMonthLimit($limit)
    {
        $limits = is_array($this->limits_month) ? $this->limits_month : [];
        $extraLimits = is_array($this->extra_limits_month) ? $this->extra_limits_month : [];

        $base = max(0, (int) ($limits[$limit] ?? 0));
        $extra = max(0, (int) ($extraLimits[$limit] ?? 0));
        $total = $base + $extra;

        return $total > 0 ? $total : false;
    }

    public function minusMonthLimit($limit)
    {
        $limits = is_array($this->limits_month) ? $this->limits_month : [];
        $extraLimits = is_array($this->extra_limits_month) ? $this->extra_limits_month : [];

        if (isset($limits[$limit]) && (int) $limits[$limit] !== 0) {
            $limits[$limit]--;
            $this->update([
                'limits_month' => $limits,
            ]);

            return true;
        }

        if (isset($extraLimits[$limit]) && (int) $extraLimits[$limit] !== 0) {
            $extraLimits[$limit]--;
            $this->update([
                'extra_limits_month' => $extraLimits,
            ]);

            return true;
        }

        return false;
    }

    // private function getLastSubscriptions()
    // {
    //     return $this->
    // }

    public function subscriber()
    {
        return $this->belongsTo(Subscribers::class, 'subscribers_id', 'id');
    }
}
