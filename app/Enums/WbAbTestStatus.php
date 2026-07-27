<?php

namespace App\Enums;

enum WbAbTestStatus: string
{
    case NotCreated = 'not_created';
    case Draft = 'draft';
    case Running = 'running';
    case Completed = 'completed';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::NotCreated => 'Не создан',
            self::Draft => 'Черновик',
            self::Running => 'В процессе',
            self::Completed => 'Завершён',
            self::Error => 'Ошибка',
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::NotCreated => 'secondary',
            self::Draft => 'outline',
            self::Running => 'default',
            self::Completed => 'success',
            self::Error => 'destructive',
        };
    }
}
