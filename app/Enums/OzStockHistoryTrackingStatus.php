<?php

namespace App\Enums;

enum OzStockHistoryTrackingStatus: string
{
    case Idle = 'idle';
    case LoadingProducts = 'loading_products';
    case LoadingStocks = 'loading_stocks';
    case Active = 'active';
    case Error = 'error';

    public function isLoading(): bool
    {
        return match ($this) {
            self::LoadingProducts, self::LoadingStocks => true,
            default => false,
        };
    }
}
