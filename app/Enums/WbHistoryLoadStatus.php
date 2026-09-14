<?php

namespace App\Enums;

enum WbHistoryLoadStatus: string
{
    case Idle = 'idle';
    case Loading = 'loading';
    case Active = 'active';
    case Error = 'error';

    public function isLoading(): bool
    {
        return $this === self::Loading;
    }
}
