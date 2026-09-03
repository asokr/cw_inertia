<?php

namespace App\Services\Admin;

use App\Enums\Credits\CreditBillingMode;
use App\Exceptions\Credits\InvalidCreditOperationException;
use App\Models\Credits\AiCabinetAnalyzerCreditCharge;
use App\Models\Credits\AiCabinetAnalyzerCreditTariff;
use App\Models\Credits\CreditService;
use App\Models\Credits\CreditServicePriceTier;
use App\Models\Credits\CreditSetting;
use App\Services\Credits\CreditPriceCalculator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

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
     *     services: array<int, array<string, mixed>>,
     *     cabinet_analyzer_models: array<int, array<string, mixed>>,
     *     cabinet_analyzer_tariffs: array<int, array<string, mixed>>,
     *     cabinet_analyzer_charges: array<string, mixed>
     * }
     */
    public function pageData(?Request $request = null): array
    {
        return [
            'rubles_per_credit' => $this->calculator->rublesPerCredit(),
            'services' => $this->services(),
            'cabinet_analyzer_models' => $this->cabinetAnalyzerModels(),
            'cabinet_analyzer_tariffs' => $this->cabinetAnalyzerTariffs(),
            'cabinet_analyzer_charges' => $this->cabinetAnalyzerCharges($request),
        ];
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

    /**
     * @return list<array<string, mixed>>
     */
    public function cabinetAnalyzerModels(): array
    {
        $gemini = (string) config('services.gemini.pro_model', 'gemini-3.1-pro-preview');
        $gpt = (string) config('services.gpt.model', 'gpt-4.1');

        return [
            [
                'tool' => 'ИИ-анализ кабинета Wildberries',
                'provider' => 'Gemini',
                'model' => $gemini,
                'fallback_provider' => 'GPT',
                'fallback_model' => $gpt,
            ],
            [
                'tool' => 'ИИ-анализ кабинета Ozon',
                'provider' => 'Gemini',
                'model' => $gemini,
                'fallback_provider' => 'GPT',
                'fallback_model' => $gpt,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function cabinetAnalyzerTariffs(): array
    {
        if (! Schema::hasTable('ai_cabinet_analyzer_credit_tariffs')) {
            return [];
        }

        return AiCabinetAnalyzerCreditTariff::query()
            ->orderBy('provider')
            ->orderByDesc('is_default')
            ->orderBy('model')
            ->get()
            ->map(fn (AiCabinetAnalyzerCreditTariff $tariff) => $this->presentTariff($tariff))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function cabinetAnalyzerCharges(?Request $request = null): array
    {
        $empty = [
            'data' => [],
            'current_page' => 1,
            'last_page' => 1,
            'per_page' => 20,
            'total' => 0,
            'marketplace' => '',
        ];

        if (! Schema::hasTable('ai_cabinet_analyzer_credit_charges')) {
            return $empty;
        }

        $marketplace = trim((string) ($request?->input('marketplace') ?? ''));
        $query = AiCabinetAnalyzerCreditCharge::query()
            ->with(['user:id,name,email'])
            ->orderByDesc('id');

        if (in_array($marketplace, ['wb', 'ozon'], true)) {
            $query->where('marketplace', $marketplace);
        } else {
            $marketplace = '';
        }

        /** @var LengthAwarePaginator $page */
        $page = $query->paginate(20)->withQueryString();

        return [
            'data' => collect($page->items())->map(fn (AiCabinetAnalyzerCreditCharge $charge) => [
                'id' => $charge->id,
                'created_at' => optional($charge->created_at)?->toDateTimeString(),
                'marketplace' => $charge->marketplace,
                'marketplace_label' => $charge->marketplaceLabel(),
                'user_id' => $charge->user_id,
                'user_email' => $charge->user?->email,
                'provider' => $charge->provider,
                'model' => $charge->model,
                'input_tokens' => $charge->input_tokens,
                'output_tokens' => $charge->output_tokens,
                'credits_reserved' => $charge->credits_reserved,
                'credits_charged' => $charge->credits_charged,
                'tariff_snapshot' => $charge->tariff_snapshot,
            ])->values()->all(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
            'marketplace' => $marketplace,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createCabinetAnalyzerTariff(array $data): AiCabinetAnalyzerCreditTariff
    {
        $this->assertTariffTable();

        $payload = $this->normalizeTariffPayload($data);
        $this->assertUniqueProviderModel($payload['provider'], $payload['model']);

        $tariff = AiCabinetAnalyzerCreditTariff::query()->create($payload);
        if ($tariff->is_default) {
            $this->ensureSingleDefault($tariff);
        }

        return $tariff->fresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateCabinetAnalyzerTariff(
        AiCabinetAnalyzerCreditTariff $tariff,
        array $data,
    ): AiCabinetAnalyzerCreditTariff {
        $this->assertTariffTable();

        $payload = $this->normalizeTariffPayload($data);
        $this->assertUniqueProviderModel($payload['provider'], $payload['model'], $tariff->id);

        if ($tariff->is_default && ! $payload['is_default'] && ! $payload['is_active']) {
            throw new InvalidCreditOperationException(
                'Нельзя одновременно снять признак «по умолчанию» и выключить единственную ставку провайдера. Сначала назначьте другую ставку по умолчанию.'
            );
        }

        $tariff->fill($payload);
        $tariff->save();

        if ($tariff->is_default) {
            $this->ensureSingleDefault($tariff);
        }

        return $tariff->fresh();
    }

    public function deleteCabinetAnalyzerTariff(AiCabinetAnalyzerCreditTariff $tariff): void
    {
        $this->assertTariffTable();

        if ($tariff->is_default) {
            $hasOtherDefault = AiCabinetAnalyzerCreditTariff::query()
                ->where('provider', $tariff->provider)
                ->where('is_default', true)
                ->where('id', '!=', $tariff->id)
                ->exists();

            if (! $hasOtherDefault) {
                throw new InvalidCreditOperationException(
                    'Нельзя удалить ставку по умолчанию. Сначала назначьте другую модель этого провайдера основной.'
                );
            }
        }

        $tariff->delete();
    }

    /**
     * @return array<string, mixed>
     */
    private function presentTariff(AiCabinetAnalyzerCreditTariff $tariff): array
    {
        return [
            'id' => $tariff->id,
            'provider' => $tariff->provider,
            'provider_label' => $tariff->providerLabel(),
            'model' => $tariff->model,
            'input_credits_per_1k' => (string) $tariff->input_credits_per_1k,
            'output_credits_per_1k' => (string) $tariff->output_credits_per_1k,
            'coefficient' => (string) $tariff->coefficient,
            'is_default' => (bool) $tariff->is_default,
            'is_active' => (bool) $tariff->is_active,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{
     *     provider: string,
     *     model: string,
     *     input_credits_per_1k: string,
     *     output_credits_per_1k: string,
     *     coefficient: string,
     *     is_default: bool,
     *     is_active: bool
     * }
     */
    private function normalizeTariffPayload(array $data): array
    {
        $provider = mb_strtolower(trim((string) ($data['provider'] ?? '')));
        $model = trim((string) ($data['model'] ?? ''));
        $input = $this->normalizeDecimal($data['input_credits_per_1k'] ?? null, 6);
        $output = $this->normalizeDecimal($data['output_credits_per_1k'] ?? null, 6);
        $coefficient = $this->normalizeDecimal($data['coefficient'] ?? 1, 4);

        if ($input === null || $output === null || $coefficient === null) {
            throw new InvalidCreditOperationException('Укажите ставки входящих и исходящих данных');
        }

        if ((float) $coefficient <= 0) {
            throw new InvalidCreditOperationException('Коэффициент должен быть больше нуля');
        }

        return [
            'provider' => $provider,
            'model' => $model,
            'input_credits_per_1k' => $input,
            'output_credits_per_1k' => $output,
            'coefficient' => $coefficient,
            'is_default' => (bool) ($data['is_default'] ?? false),
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
        ];
    }

    private function normalizeDecimal(mixed $value, int $decimals): ?string
    {
        if (! is_numeric($value) || (float) $value < 0) {
            return null;
        }

        return number_format((float) $value, $decimals, '.', '');
    }

    private function assertTariffTable(): void
    {
        if (! Schema::hasTable('ai_cabinet_analyzer_credit_tariffs')) {
            throw new InvalidCreditOperationException('Таблица ставок ИИ-анализа ещё не создана');
        }
    }

    private function assertUniqueProviderModel(string $provider, string $model, ?int $ignoreId = null): void
    {
        $query = AiCabinetAnalyzerCreditTariff::query()
            ->where('provider', $provider)
            ->where('model', $model);

        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'model' => 'Ставка для этой модели уже есть',
            ]);
        }
    }

    private function ensureSingleDefault(AiCabinetAnalyzerCreditTariff $tariff): void
    {
        AiCabinetAnalyzerCreditTariff::query()
            ->where('provider', $tariff->provider)
            ->where('id', '!=', $tariff->id)
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }
}
