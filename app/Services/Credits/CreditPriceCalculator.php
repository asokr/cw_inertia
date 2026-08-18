<?php

namespace App\Services\Credits;

use App\Enums\Credits\CreditBillingMode;
use App\Exceptions\Credits\CreditPriceNotFoundException;
use App\Models\Credits\CreditService;
use App\Models\Credits\CreditServicePriceTier;
use App\Models\Credits\CreditSetting;
use Illuminate\Support\Facades\Schema;

class CreditPriceCalculator
{
    public const DEFAULT_RUBLES_PER_CREDIT = '2';

    public function isReady(): bool
    {
        return Schema::hasTable('credit_services')
            && Schema::hasTable('credit_service_price_tiers')
            && Schema::hasTable('credit_settings');
    }

    /**
     * Стоимость одного кредита в рублях. Читается из настройки, не из константы покупки.
     */
    public function rublesPerCredit(): string
    {
        if (! $this->isReady()) {
            return self::DEFAULT_RUBLES_PER_CREDIT;
        }

        $value = CreditSetting::query()
            ->where('key', CreditSetting::RUBLES_PER_CREDIT)
            ->value('value');

        if (! is_numeric($value) || (float) $value < 0) {
            return self::DEFAULT_RUBLES_PER_CREDIT;
        }

        return $this->normalizeMoney((string) $value);
    }

    public function purchaseCost(int $quantity): string
    {
        if ($quantity < 1) {
            throw new CreditPriceNotFoundException('Количество кредитов должно быть больше нуля');
        }

        $unit = (float) $this->rublesPerCredit();
        $total = round($quantity * $unit, 2);

        return $this->normalizeMoney((string) $total);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function quote(string $code, array $params = []): CreditQuote
    {
        if (! $this->isReady()) {
            throw new CreditPriceNotFoundException('Каталог стоимости кредитов ещё не готов');
        }

        $service = CreditService::query()
            ->where('code', $code)
            ->where('is_active', true)
            ->with('tiers')
            ->first();

        if (! $service) {
            throw new CreditPriceNotFoundException('Стоимость услуги не найдена');
        }

        return match ($service->billing_mode) {
            CreditBillingMode::Fixed => $this->quoteFixed($service, $params),
            CreditBillingMode::ByResolution => $this->quoteByResolution($service, $params, false),
            CreditBillingMode::PerSecondByResolution => $this->quoteByResolution($service, $params, true),
        };
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function quoteFixed(CreditService $service, array $params): CreditQuote
    {
        $amount = (int) ($service->amount ?? 0);
        if ($amount < 1) {
            throw new CreditPriceNotFoundException('Для услуги не задана фиксированная стоимость');
        }

        return new CreditQuote(
            amount: $amount,
            serviceCode: $service->code,
            billingMode: $service->billing_mode->value,
            params: $params,
            unitAmount: $amount,
        );
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function quoteByResolution(CreditService $service, array $params, bool $perSecond): CreditQuote
    {
        $resolution = $this->normalizeResolution((string) ($params['resolution'] ?? 'default'));
        $tier = $this->findTier($service, 'resolution', $resolution);

        if (! $tier) {
            throw new CreditPriceNotFoundException('Для выбранного разрешения нет стоимости в кредитах');
        }

        $unitAmount = (int) $tier->amount;
        if ($unitAmount < 1) {
            throw new CreditPriceNotFoundException('Стоимость разрешения должна быть больше нуля');
        }

        $duration = max(1, (int) ($params['duration'] ?? 1));
        $amount = $perSecond ? $unitAmount * $duration : $unitAmount;

        return new CreditQuote(
            amount: $amount,
            serviceCode: $service->code,
            billingMode: $service->billing_mode->value,
            params: array_merge($params, [
                'resolution' => $tier->param_value,
                'duration' => $perSecond ? $duration : ($params['duration'] ?? null),
            ]),
            unitAmount: $unitAmount,
        );
    }

    private function findTier(CreditService $service, string $paramKey, string $paramValue): ?CreditServicePriceTier
    {
        $needle = mb_strtolower($paramValue);

        return $service->tiers->first(function (CreditServicePriceTier $tier) use ($paramKey, $needle) {
            return $tier->param_key === $paramKey
                && mb_strtolower((string) $tier->param_value) === $needle;
        });
    }

    public function normalizeResolution(string $resolution): string
    {
        $normalized = mb_strtolower(trim($resolution));

        return match ($normalized) {
            '', 'default', 'standart', 'standard' => 'default',
            '1k' => '1K',
            '2k' => '2K',
            '4k' => '4K',
            default => trim($resolution) !== '' ? trim($resolution) : 'default',
        };
    }

    private function normalizeMoney(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
