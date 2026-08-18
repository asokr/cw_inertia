<?php

namespace App\Models\Credits;

use Illuminate\Database\Eloquent\Model;

class CreditSetting extends Model
{
    public const RUBLES_PER_CREDIT = 'rubles_per_credit';

    protected $fillable = [
        'key',
        'value',
    ];
}
