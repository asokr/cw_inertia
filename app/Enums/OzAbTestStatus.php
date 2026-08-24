<?php

namespace App\Enums;

enum OzAbTestStatus: string
{
    case NotCreated = 'not_created';
    case Draft = 'draft';
    case Running = 'running';
    case Completed = 'completed';
    case Stopped = 'stopped';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::NotCreated => 'Не создан',
            self::Draft => 'Черновик',
            self::Running => 'В процессе',
            self::Completed => 'Завершён',
            self::Stopped => 'Остановлен',
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
            self::Stopped => 'warning',
            self::Error => 'destructive',
        };
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Stopped, self::Error => true,
            default => false,
        };
    }

    public function isEditable(): bool
    {
        return match ($this) {
            self::Draft, self::Stopped, self::Error => true,
            default => false,
        };
    }

    public function isStartable(): bool
    {
        return match ($this) {
            self::Draft, self::Stopped, self::Error => true,
            default => false,
        };
    }
}
