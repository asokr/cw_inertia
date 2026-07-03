<?php

namespace App\Models;

use Laravel\Passport\HasApiTokens;
use O21\LaravelWallet\Models\Balance;
use Spatie\Permission\Traits\HasRoles;
use App\Models\Subscribers\Subscribers;
use Illuminate\Notifications\Notifiable;
use O21\LaravelWallet\Contracts\Payable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Auth\CanResetPassword;
use App\Notifications\ResetPasswordNotification;
use O21\LaravelWallet\Models\Concerns\HasBalance;
use App\Models\Subscribers\SubscribersSubscriptions;
use LaravelAndVueJS\Traits\LaravelPermissionToVueJS;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;


class User extends Authenticatable implements MustVerifyEmail, Payable, CanResetPassword
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles, LaravelPermissionToVueJS, HasBalance;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'surname',
        'email',
        'phone',
        'password',
        'has_seen_tour',
        'vk_id',
        'yandex_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    public function sendPasswordResetNotification($token)
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    public $guard_name = 'web';

    public function getFullName()
    {
        return ucfirst($this->name) . ' (' .  $this->email . ')';
    }

    public function subscriber()
    {
        return $this->hasOne(Subscribers::class, 'user_id', 'id');
    }

    public function subscriberId(): ?int
    {
        return $this->subscriber?->id;
    }

    public function balances()
    {
        return $this->hasOne(Balance::class, 'payable_id', 'id');
    }

    public function getSubscriptions()
    {

        $subscriber = Subscribers::where('user_id', $this->id)->first();
        if (!$subscriber) {
            return null;
        }
        $subsription = SubscribersSubscriptions::where([
            'subscribers_id' => $subscriber->id
        ])->first();
        return $subsription;
    }

    public function getSubscriberLimits()
    {
        $subscriber = Subscribers::where('user_id', $this->id)->first();
        $subsriptions = SubscribersSubscriptions::where([
            'subscribers_id' => $subscriber->id,
            'status' => 1,
        ])->get();

        $limits_month = array();
        $limits_plan = array();

        foreach ($subsriptions as $subsription) {
            $limits_month = array_merge($limits_month, $subsription['limits_month']);
            $limits_plan = array_merge($limits_plan, $subsription['limits_plan']);
        }

        return [
            'limits_month' => $limits_month,
            'limits_plan' => $limits_plan
        ];
    }

    public static function boot()
    {
        parent::boot();

        static::creating(function ($query) {
            $query->surname = '';
        });
    }
}
