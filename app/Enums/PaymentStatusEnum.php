<?php

namespace App\Enums;

class PaymentStatusEnum
{
    public const CREATE = 'CREATE';
    public const FAILED = 'FAILED';
    public const CANCELED = 'CANCELED';
    public const CONFIRMED = 'CONFIRMED';
    public const RETURNED = 'RETURNED';
}
