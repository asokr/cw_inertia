<?php

namespace App\Enums\Credits;

enum CreditLedgerDirection: string
{
    case Credit = 'credit';
    case Debit = 'debit';
}
