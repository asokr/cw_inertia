<?php

namespace App\Enums;

enum WbAbTestStatus: string
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

    /** Settings / photos / campaign can be changed. */
    public function isEditable(): bool
    {
        return match ($this) {
            self::Draft, self::Stopped => true,
            default => false,
        };
    }

    /** Experiment may be (re)started. */
    public function isStartable(): bool
    {
        return match ($this) {
            self::Draft, self::Stopped => true,
            default => false,
        };
    }
}
