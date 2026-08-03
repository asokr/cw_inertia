<?php

namespace App\Support\Wb;

use Illuminate\Support\Facades\Cache;

/**
 * Prevents parallel WB Price Calc heavy operations (sync / import+calculate)
 * and enforces a short cooldown after success or 429 to respect personal token limits.
 */
final class WbPriceCalcOperationGuard
{
    public const BUSY_TTL_SECONDS = 900;

    public const COOLDOWN_SECONDS = 60;

    public const COOLDOWN_AFTER_429_SECONDS = 65;

    public function busyKey(int $cabinetId): string
    {
        return "wb_price_calc:busy:{$cabinetId}";
    }

    public function cooldownKey(int $cabinetId): string
    {
        return "wb_price_calc:cooldown:{$cabinetId}";
    }

    /**
     * @return array{busy: bool, retry_after: int, reason: string|null}
     */
    public function state(int $cabinetId): array
    {
        $busy = (bool) Cache::get($this->busyKey($cabinetId), false);
        $cooldownUntil = (int) Cache::get($this->cooldownKey($cabinetId), 0);
        $now = time();

        $retryAfter = 0;
        $reason = null;

        if ($busy) {
            $reason = 'busy';
            // Soft estimate: client shows generic "processing"; exact TTL is not critical.
            $retryAfter = max($retryAfter, 5);
        }

        if ($cooldownUntil > $now) {
            $seconds = $cooldownUntil - $now;
            if ($seconds > $retryAfter) {
                $retryAfter = $seconds;
                $reason = $reason ?? 'cooldown';
            } elseif ($reason === null) {
                $reason = 'cooldown';
            }
        }

        return [
            'busy' => $busy,
            'retry_after' => $retryAfter,
            'reason' => $reason,
        ];
    }

    /**
     * @return array{ok: true}|array{ok: false, message: string, retry_after: int, reason: string}
     */
    public function acquire(int $cabinetId): array
    {
        $state = $this->state($cabinetId);

        if ($state['busy']) {
            return [
                'ok' => false,
                'message' => 'Идёт обработка данных. Дождитесь завершения текущей операции.',
                'retry_after' => max(5, $state['retry_after']),
                'reason' => 'busy',
            ];
        }

        if ($state['retry_after'] > 0 && $state['reason'] === 'cooldown') {
            return [
                'ok' => false,
                'message' => "Слишком частые запросы к API Wildberries. Повторите через {$state['retry_after']} с.",
                'retry_after' => $state['retry_after'],
                'reason' => 'cooldown',
            ];
        }

        $acquired = Cache::add($this->busyKey($cabinetId), 1, self::BUSY_TTL_SECONDS);
        if (! $acquired) {
            return [
                'ok' => false,
                'message' => 'Идёт обработка данных. Дождитесь завершения текущей операции.',
                'retry_after' => 5,
                'reason' => 'busy',
            ];
        }

        return ['ok' => true];
    }

    public function release(int $cabinetId): void
    {
        Cache::forget($this->busyKey($cabinetId));
    }

    public function setCooldown(int $cabinetId, ?int $seconds = null): void
    {
        $seconds ??= self::COOLDOWN_SECONDS;
        $until = time() + max(1, $seconds);
        Cache::put($this->cooldownKey($cabinetId), $until, $seconds + 5);
    }

    public function setCooldownAfter429(int $cabinetId): void
    {
        $this->setCooldown($cabinetId, self::COOLDOWN_AFTER_429_SECONDS);
    }
}
