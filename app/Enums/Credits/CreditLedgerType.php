<?php

namespace App\Enums\Credits;

enum CreditLedgerType: string
{
    case GrantSubscription = 'grant_subscription';
    case Purchase = 'purchase';
    case Spend = 'spend';
    case Hold = 'hold';
    case Capture = 'capture';
    case Release = 'release';
    case Refund = 'refund';
    case AdminAdjust = 'admin_adjust';
    case Migration = 'migration';

    public function userLabel(): string
    {
        return match ($this) {
            self::GrantSubscription => 'Начисление по тарифу',
            self::Purchase => 'Покупка кредитов',
            self::Spend => 'Списание',
            self::Hold => 'Резерв',
            self::Capture => 'Списание из резерва',
            self::Release => 'Возврат резерва',
            self::Refund => 'Возврат',
            self::AdminAdjust => 'Корректировка администратором',
            self::Migration => 'Перенос остатков',
        };
    }
}
