<?php

namespace App\Services\Admin;

use App\Enums\Credits\CreditBillingMode;
use App\Exceptions\Credits\InvalidCreditOperationException;
use App\Models\Credits\CreditService;
use App\Models\Credits\CreditServicePriceTier;
use App\Models\Credits\CreditSetting;
use App\Services\Credits\CreditPriceCalculator;
use Database\Seeders\CreditPricingSeeder;
use Illuminate\Support\Facades\Schema;

class AdminCreditPricingService
{
    public function __construct(
        private readonly CreditPriceCalculator $calculator,
    ) {
    }

    public function isReady(): bool
    {
        return $this->calculator->isReady();
    }

    /**
     * @return array{
     *     rubles_per_credit: string,
     *     services: array<int, array<string, mixed>>
     * }
     */
    public function pageData(): array
    {
        $this->ensureCatalog();

        return [
            'rubles_per_credit' => $this->calculator->rublesPerCredit(),
            'services' => $this->services(),
        ];
    }

    /**
     * Если таблицы есть, а каталог пустой — заполняем сидером.
     * Повторный вызов безопасен: существующие цены не перезаписываются.
     */
    public function ensureCatalog(): void
    {
        if (! $this->isReady()) {
            return;
        }

        if (CreditService::query()->exists()
            && CreditSetting::query()->where('key', CreditSetting::RUBLES_PER_CREDIT)->exists()
        ) {
            return;
        }

        (new CreditPricingSeeder())->run();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function services(): array
    {
        if (! $this->isReady()) {
            return [];
        }

        return CreditService::query()
            ->with('tiers')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (CreditService $service) => [
                'id' => $service->id,
                'code' => $service->code,
                'name' => $service->name,
                'billing_mode' => $service->billing_mode->value,
                'billing_mode_label' => $service->billing_mode->label(),
                'amount' => $service->amount,
                'is_active' => $service->is_active,
                'tiers' => $service->tiers->map(fn (CreditServicePriceTier $tier) => [
                    'id' => $tier->id,
                    'param_key' => $tier->param_key,
                    'param_value' => $tier->param_value,
                    'amount' => $tier->amount,
                    'sort_order' => $tier->sort_order,
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    public function updateRublesPerCredit(mixed $value): string
    {
        if (! $this->isReady()) {
            throw new InvalidCreditOperationException('Каталог стоимости кредитов ещё не готов');
        }

        if (! is_numeric($value) || (float) $value < 0) {
            throw new InvalidCreditOperationException('Укажите стоимость одного кредита');
        }

        $normalized = number_format((float) $value, 2, '.', '');

        CreditSetting::query()->updateOrCreate(
            ['key' => CreditSetting::RUBLES_PER_CREDIT],
            ['value' => $normalized],
        );

        return $normalized;
    }

    public function updateFixedAmount(CreditService $service, int $amount): CreditService
    {
        if ($service->billing_mode !== CreditBillingMode::Fixed) {
            throw new InvalidCreditOperationException('У этой услуги нет фиксированной стоимости');
        }

        if ($amount < 1) {
            throw new InvalidCreditOperationException('Стоимость должна быть больше нуля');
        }

        $service->amount = $amount;
        $service->save();

        return $service->fresh(['tiers']);
    }

    /**
     * @param  array{param_value: string, amount: int, sort_order?: int}  $data
     */
    public function addResolution(CreditService $service, array $data): CreditServicePriceTier
    {
        $this->assertResolutionService($service);

        $paramValue = $this->normalizeParamValue((string) $data['param_value']);
        $amount = (int) $data['amount'];

        if ($paramValue === '') {
            throw new InvalidCreditOperationException('Укажите разрешение');
        }

        if ($amount < 1) {
            throw new InvalidCreditOperationException('Стоимость должна быть больше нуля');
        }

        $exists = $service->tiers()
            ->where('param_key', 'resolution')
            ->get()
            ->contains(fn (CreditServicePriceTier $tier) => mb_strtolower($tier->param_value) === mb_strtolower($paramValue));

        if ($exists) {
            throw new InvalidCreditOperationException('Такое разрешение уже добавлено');
        }

        return $service->tiers()->create([
            'param_key' => 'resolution',
            'param_value' => $paramValue,
            'amount' => $amount,
            'sort_order' => (int) ($data['sort_order'] ?? (($service->tiers()->max('sort_order') ?? 0) + 10)),
        ]);
    }

    /**
     * @param  array{param_value?: string, amount: int}  $data
     */
    public function updateTier(CreditServicePriceTier $tier, array $data): CreditServicePriceTier
    {
        $amount = (int) $data['amount'];
        if ($amount < 1) {
            throw new InvalidCreditOperationException('Стоимость должна быть больше нуля');
        }

        $payload = ['amount' => $amount];

        if (isset($data['param_value'])) {
            $paramValue = $this->normalizeParamValue((string) $data['param_value']);
            if ($paramValue === '') {
                throw new InvalidCreditOperationException('Укажите разрешение');
            }
            $payload['param_value'] = $paramValue;
        }

        $tier->update($payload);

        return $tier->fresh();
    }

    public function deleteTier(CreditServicePriceTier $tier): void
    {
        $tier->delete();
    }

    private function assertResolutionService(CreditService $service): void
    {
        if (! in_array($service->billing_mode, [
            CreditBillingMode::ByResolution,
            CreditBillingMode::PerSecondByResolution,
        ], true)) {
            throw new InvalidCreditOperationException('Для этой услуги нельзя добавить разрешение');
        }
    }

    private function normalizeParamValue(string $value): string
    {
        return $this->calculator->normalizeResolution($value);
    }
}
