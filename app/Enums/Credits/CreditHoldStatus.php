<?php

namespace App\Enums\Credits;

enum CreditHoldStatus: string
{
    case Held = 'held';
    case Captured = 'captured';
    case Released = 'released';
    case Expired = 'expired';
}
