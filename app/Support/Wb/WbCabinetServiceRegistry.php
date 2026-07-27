<?php

namespace App\Support\Wb;

use App\Models\Subscribers\Wb\AiCabinetAnalyzer\AiCabinetAnalyzerCabinet;
use App\Models\Subscribers\Wb\AiCabinetAnalyzer\AiCabinetAnalyzerReport;
use App\Models\Subscribers\Wb\Feedbacks\FeedbacksClients;
use App\Models\Subscribers\Wb\Feedbacks\FeedbacksTemplates;
use App\Models\Subscribers\Wb\Feedbacks\Review;
use App\Models\Subscribers\Wb\Feedbacks\ReviewCategoryStatistic;
use App\Models\Subscribers\Wb\Feedbacks\ReviewProductStatistic;
use App\Models\Subscribers\Wb\Feedbacks\ReviewStatistic;
use App\Models\Subscribers\Wb\PriceCalculation\PriceCalculationCabinets;
use App\Models\Subscribers\Wb\PriceCalculation\PriceCalculationData;
use App\Models\Subscribers\Wb\PriceCalculation\PriceCalculationSpecialData;
use App\Models\Subscribers\Wb\PriceCalculation\PriceCalculationV2Data;
use App\Models\Subscribers\Wb\PriceCalculation\PriceCalculationV2Settings;
use App\Models\Subscribers\Wb\PriceCalculation\PriceCalculationV3Data;
use App\Models\Subscribers\Wb\Profitability\ProfitabilityCabinet;
use App\Models\Subscribers\Wb\Profitability\Report as ProfitabilityReport;
use App\Models\Subscribers\Wb\Repricer\RepricerCabinets;
use App\Models\Subscribers\Wb\Repricer\RepricerCompetitor;
use App\Models\Subscribers\Wb\Repricer\RepricerLogs;
use App\Models\Subscribers\Wb\Repricer\RepricerSettings;
use App\Models\Subscribers\Wb\Repricer\RepricerStocks;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class WbCabinetServiceRegistry
{
    public const OWNER_USER = 'user_id';

    public const OWNER_SUBSCRIBER = 'subscriber_id';

    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     model: class-string<Model>,
     *     table: string,
     *     owner: string,
     *     child_rewrites: list<array{model: class-string<Model>, column: string}>,
     *     settings: ?string
     * }>
     */
    public function all(): array
    {
        return array_values(array_filter([
            $this->definition(
                key: 'feedbacks',
                label: 'Управление отзывами',
                model: FeedbacksClients::class,
                owner: self::OWNER_SUBSCRIBER,
                childRewrites: [
                    ['model' => Review::class, 'column' => 'cabinet_id'],
                    ['model' => ReviewStatistic::class, 'column' => 'cabinet_id'],
                    ['model' => ReviewProductStatistic::class, 'column' => 'cabinet_id'],
                    ['model' => ReviewCategoryStatistic::class, 'column' => 'cabinet_id'],
                    ['model' => FeedbacksTemplates::class, 'column' => 'client_id'],
                ],
                settings: 'feedbacks',
            ),
            $this->definition(
                key: 'price_calc',
                label: 'Ценообразование',
                model: PriceCalculationCabinets::class,
                owner: self::OWNER_USER,
                childRewrites: [
                    ['model' => PriceCalculationV3Data::class, 'column' => 'cabinet_id'],
                    ['model' => PriceCalculationV2Settings::class, 'column' => 'cabinet_id'],
                    ['model' => PriceCalculationV2Data::class, 'column' => 'cabinet_id'],
                    ['model' => PriceCalculationData::class, 'column' => 'cabinet_id'],
                    ['model' => PriceCalculationSpecialData::class, 'column' => 'cabinet_id'],
                ],
            ),
            $this->definition(
                key: 'repricer',
                label: 'Репрайсер',
                model: RepricerCabinets::class,
                owner: self::OWNER_USER,
                childRewrites: [
                    ['model' => RepricerSettings::class, 'column' => 'cabinet_id'],
                    ['model' => RepricerStocks::class, 'column' => 'cabinet_id'],
                    ['model' => RepricerLogs::class, 'column' => 'cabinet_id'],
                    ['model' => RepricerCompetitor::class, 'column' => 'cabinet_id'],
                ],
                settings: 'repricer',
            ),
            $this->definition(
                key: 'profitability',
                label: 'Рентабельность',
                model: ProfitabilityCabinet::class,
                owner: self::OWNER_USER,
                childRewrites: [
                    ['model' => ProfitabilityReport::class, 'column' => 'cabinet_id'],
                ],
            ),
            $this->definition(
                key: 'ai_cabinet_analyzer',
                label: 'ИИ анализ кабинета',
                model: AiCabinetAnalyzerCabinet::class,
                owner: self::OWNER_USER,
                childRewrites: [
                    ['model' => AiCabinetAnalyzerReport::class, 'column' => 'cabinet_id'],
                ],
            ),
        ]));
    }

    /**
     * @return array{
     *     key: string,
     *     label: string,
     *     model: class-string<Model>,
     *     table: string,
     *     owner: string,
     *     child_rewrites: list<array{model: class-string<Model>, column: string}>,
     *     settings: ?string
     * }|null
     */
    public function get(string $key): ?array
    {
        foreach ($this->all() as $service) {
            if ($service['key'] === $key) {
                return $service;
            }
        }

        return null;
    }

    /**
     * @return Builder<Model>|null
     */
    public function unmigratedQueryForUser(array $service, User $user): ?Builder
    {
        if (! Schema::hasTable($service['table'])) {
            return null;
        }

        /** @var class-string<Model> $modelClass */
        $modelClass = $service['model'];
        $query = $modelClass::query();

        if ($service['owner'] === self::OWNER_SUBSCRIBER) {
            $subscriberId = $user->subscriberId();
            if (! $subscriberId) {
                return $query->whereRaw('0 = 1');
            }

            $query->where('subscriber_id', $subscriberId);
        } else {
            $query->where('user_id', $user->id);
        }

        // If tracking column is not deployed yet, treat every legacy row as unmigrated.
        if (Schema::hasColumn($service['table'], 'is_migrated')) {
            $query->where(function (Builder $builder) {
                $builder->where('is_migrated', false)->orWhereNull('is_migrated');
            });
        }

        return $query;
    }

    public function userNeedsMigration(User $user): bool
    {
        foreach ($this->all() as $service) {
            $query = $this->unmigratedQueryForUser($service, $user);
            if ($query && $query->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     cabinets: list<array{id: int, name: string, created_at: mixed}>
     * }>
     */
    public function inventoryForUser(User $user): array
    {
        $groups = [];

        foreach ($this->all() as $service) {
            $query = $this->unmigratedQueryForUser($service, $user);
            if (! $query) {
                continue;
            }

            $cabinets = $query
                ->orderByDesc('id')
                ->get()
                ->map(fn (Model $row) => [
                    'id' => (int) $row->getKey(),
                    'name' => (string) ($row->getAttribute('name') ?? ('#'.$row->getKey())),
                    'created_at' => $row->getAttribute('created_at'),
                ])
                ->values()
                ->all();

            if ($cabinets === []) {
                continue;
            }

            $groups[] = [
                'key' => $service['key'],
                'label' => $service['label'],
                'cabinets' => $cabinets,
            ];
        }

        return $groups;
    }

    /**
     * @param  list<array{model: class-string<Model>, column: string}>  $childRewrites
     * @return array{
     *     key: string,
     *     label: string,
     *     model: class-string<Model>,
     *     table: string,
     *     owner: string,
     *     child_rewrites: list<array{model: class-string<Model>, column: string}>,
     *     settings: ?string
     * }|null
     */
    private function definition(
        string $key,
        string $label,
        string $model,
        string $owner,
        array $childRewrites,
        ?string $settings = null,
    ): ?array {
        /** @var class-string<Model> $model */
        $table = (new $model)->getTable();

        if (! Schema::hasTable($table)) {
            return null;
        }

        return [
            'key' => $key,
            'label' => $label,
            'model' => $model,
            'table' => $table,
            'owner' => $owner,
            'child_rewrites' => $childRewrites,
            'settings' => $settings,
        ];
    }
}
