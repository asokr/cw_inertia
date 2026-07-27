<?php

namespace App\Services\Subscriber\Wb;

use App\Models\Subscribers\Wb\AiCabinetAnalyzer\AiCabinetAnalyzerAiAnalysis;
use App\Models\Subscribers\Wb\AiCabinetAnalyzer\AiCabinetAnalyzerReport;
use App\Models\Subscribers\Wb\Feedbacks\BotResponse;
use App\Models\Subscribers\Wb\Feedbacks\FeedbacksClients;
use App\Models\Subscribers\Wb\Feedbacks\FeedbacksTemplates;
use App\Models\Subscribers\Wb\Feedbacks\Review;
use App\Models\Subscribers\Wb\Feedbacks\WbFeedbacksSettings;
use App\Models\Subscribers\Wb\Profitability\Item as ProfitabilityItem;
use App\Models\Subscribers\Wb\Profitability\Report as ProfitabilityReport;
use App\Models\Subscribers\Wb\Repricer\RepricerCabinets;
use App\Models\Subscribers\Wb\WbCabinet;
use App\Models\User;
use App\Support\Wb\WbCabinetServiceRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class WbCabinetMigrationService
{
    public function __construct(
        private readonly WbCabinetServiceRegistry $registry,
        private readonly WbCabinetService $cabinetService,
    ) {
    }

    public function needsMigration(User $user): bool
    {
        return $this->registry->userNeedsMigration($user);
    }

    /**
     * @return array{
     *     needs_migration: bool,
     *     cabinets: list<array{id: int, name: string}>,
     *     services: list<array{key: string, label: string, cabinets: list<array{id: int, name: string, created_at: mixed}>}>,
     *     progress: array{total: int, remaining: int, mapped: int},
     *     api_key_warning: string
     * }
     */
    public function wizardState(User $user): array
    {
        $services = $this->registry->inventoryForUser($user);
        $total = 0;
        foreach ($services as $group) {
            $total += count($group['cabinets']);
        }

        return [
            'needs_migration' => $total > 0,
            'cabinets' => $this->cabinetService->listSummaries($user),
            'services' => $services,
            'progress' => [
                'total' => $total,
                'remaining' => $total,
                'mapped' => 0,
            ],
            'api_key_warning' => WbCabinetService::API_KEY_WARNING,
        ];
    }

    /**
     * @param  list<array{wb_cabinet_id: int, mappings: list<array{service: string, old_cabinet_id: int}>}>  $assignments
     * @param  list<array{service: string, old_cabinet_id: int}>  $deletions
     */
    public function migrate(User $user, array $assignments, array $deletions = []): void
    {
        if (! $this->needsMigration($user)) {
            return;
        }

        [$normalizedAssignments, $normalizedDeletions] = $this->validateAssignmentsAndDeletions(
            $user,
            $assignments,
            $deletions
        );

        DB::transaction(function () use ($user, $normalizedAssignments, $normalizedDeletions) {
            foreach ($normalizedDeletions as $deletion) {
                $this->deleteOne($user, $deletion['service'], $deletion['old_cabinet_id']);
            }

            foreach ($normalizedAssignments as $wbCabinetId => $serviceMap) {
                /** @var array<string, int> $serviceMap */
                foreach ($serviceMap as $serviceKey => $oldId) {
                    $this->migrateOne($user, $serviceKey, $oldId, $wbCabinetId);
                }
            }

            if (! $user->selected_wb_cabinet_id) {
                $firstId = WbCabinet::query()
                    ->where('user_id', $user->id)
                    ->orderBy('id')
                    ->value('id');
                if ($firstId) {
                    $user->forceFill(['selected_wb_cabinet_id' => $firstId])->save();
                }
            }

            if ($this->registry->userNeedsMigration($user->fresh())) {
                throw ValidationException::withMessages([
                    'mappings' => 'Не все старые кабинеты обработаны. Привяжите или удалите оставшиеся.',
                ]);
            }
        });
    }

    /**
     * @param  list<array{wb_cabinet_id: int, mappings: list<array{service: string, old_cabinet_id: int}>}>  $assignments
     * @param  list<array{service: string, old_cabinet_id: int}>  $deletions
     * @return array{0: array<int, array<string, int>>, 1: list<array{service: string, old_cabinet_id: int}>}
     */
    private function validateAssignmentsAndDeletions(User $user, array $assignments, array $deletions): array
    {
        $inventory = $this->registry->inventoryForUser($user);
        $allowedOld = [];
        foreach ($inventory as $group) {
            foreach ($group['cabinets'] as $cabinet) {
                $allowedOld[$group['key']][(int) $cabinet['id']] = true;
            }
        }

        if ($allowedOld === []) {
            return [[], []];
        }

        $usedOld = [];
        $resultAssignments = [];

        foreach ($assignments as $index => $assignment) {
            $wbCabinetId = (int) ($assignment['wb_cabinet_id'] ?? 0);
            $wbCabinet = $this->cabinetService->findOwned($user, $wbCabinetId);
            if (! $wbCabinet) {
                throw ValidationException::withMessages([
                    "assignments.$index.wb_cabinet_id" => 'Новый кабинет не найден.',
                ]);
            }

            $serviceMap = [];
            foreach ($assignment['mappings'] ?? [] as $mapIndex => $mapping) {
                $service = (string) ($mapping['service'] ?? '');
                $oldId = (int) ($mapping['old_cabinet_id'] ?? 0);

                if ($service === '' || $oldId <= 0) {
                    throw ValidationException::withMessages([
                        "assignments.$index.mappings.$mapIndex" => 'Некорректное соответствие кабинета.',
                    ]);
                }

                if (! isset($allowedOld[$service][$oldId])) {
                    throw ValidationException::withMessages([
                        "assignments.$index.mappings.$mapIndex" => 'Старый кабинет не найден или уже перенесён.',
                    ]);
                }

                if (isset($serviceMap[$service])) {
                    throw ValidationException::withMessages([
                        "assignments.$index.mappings.$mapIndex" => 'К одному общему кабинету можно привязать не более одного кабинета из каждого сервиса.',
                    ]);
                }

                $oldKey = $service.':'.$oldId;
                if (isset($usedOld[$oldKey])) {
                    throw ValidationException::withMessages([
                        "assignments.$index.mappings.$mapIndex" => 'Этот старый кабинет уже обработан (привязан или помечен на удаление).',
                    ]);
                }

                $serviceMap[$service] = $oldId;
                $usedOld[$oldKey] = 'map';
            }

            if ($serviceMap !== []) {
                $resultAssignments[$wbCabinetId] = $serviceMap;
            }
        }

        $normalizedDeletions = [];
        foreach ($deletions as $index => $deletion) {
            $service = (string) ($deletion['service'] ?? '');
            $oldId = (int) ($deletion['old_cabinet_id'] ?? 0);

            if ($service === '' || $oldId <= 0) {
                throw ValidationException::withMessages([
                    "deletions.$index" => 'Некорректное указание кабинета на удаление.',
                ]);
            }

            if (! isset($allowedOld[$service][$oldId])) {
                throw ValidationException::withMessages([
                    "deletions.$index" => 'Старый кабинет для удаления не найден или уже перенесён.',
                ]);
            }

            $oldKey = $service.':'.$oldId;
            if (isset($usedOld[$oldKey])) {
                throw ValidationException::withMessages([
                    "deletions.$index" => 'Нельзя удалить кабинет, который уже привязан к общему.',
                ]);
            }

            $usedOld[$oldKey] = 'delete';
            $normalizedDeletions[] = [
                'service' => $service,
                'old_cabinet_id' => $oldId,
            ];
        }

        foreach ($allowedOld as $service => $ids) {
            foreach (array_keys($ids) as $oldId) {
                $oldKey = $service.':'.$oldId;
                if (! isset($usedOld[$oldKey])) {
                    throw ValidationException::withMessages([
                        'mappings' => 'Необходимо привязать или удалить все старые кабинеты. Остались необработанные.',
                    ]);
                }
            }
        }

        if ($resultAssignments === [] && $normalizedDeletions === []) {
            throw ValidationException::withMessages([
                'mappings' => 'Укажите привязку или удаление старых кабинетов.',
            ]);
        }

        return [$resultAssignments, $normalizedDeletions];
    }

    private function migrateOne(User $user, string $serviceKey, int $oldId, int $newCabinetId): void
    {
        $service = $this->registry->get($serviceKey);
        if (! $service) {
            throw ValidationException::withMessages([
                'mappings' => 'Неизвестный сервис: '.$serviceKey,
            ]);
        }

        /** @var class-string<Model> $modelClass */
        $modelClass = $service['model'];
        $query = $this->registry->unmigratedQueryForUser($service, $user);
        if (! $query) {
            return;
        }

        /** @var Model|null $old */
        $old = $query->where('id', $oldId)->lockForUpdate()->first();
        if (! $old) {
            throw ValidationException::withMessages([
                'mappings' => 'Старый кабинет не найден: '.$serviceKey.'#'.$oldId,
            ]);
        }

        foreach ($service['child_rewrites'] as $rewrite) {
            $this->rewriteChildren($rewrite['model'], $rewrite['column'], $oldId, $newCabinetId);
        }

        $this->migrateSettings($service, $old, $newCabinetId);

        $updates = [];
        $table = $service['table'];
        if (Schema::hasColumn($table, 'is_migrated')) {
            $updates['is_migrated'] = true;
        }
        if (Schema::hasColumn($table, 'migrated_at')) {
            $updates['migrated_at'] = now();
        }
        if (Schema::hasColumn($table, 'wb_cabinet_id')) {
            $updates['wb_cabinet_id'] = $newCabinetId;
        }

        if ($updates !== []) {
            $old->forceFill($updates)->save();
        }
    }

    private function deleteOne(User $user, string $serviceKey, int $oldId): void
    {
        $service = $this->registry->get($serviceKey);
        if (! $service) {
            throw ValidationException::withMessages([
                'mappings' => 'Неизвестный сервис: '.$serviceKey,
            ]);
        }

        $query = $this->registry->unmigratedQueryForUser($service, $user);
        if (! $query) {
            return;
        }

        /** @var Model|null $old */
        $old = $query->where('id', $oldId)->lockForUpdate()->first();
        if (! $old) {
            throw ValidationException::withMessages([
                'mappings' => 'Старый кабинет для удаления не найден: '.$serviceKey.'#'.$oldId,
            ]);
        }

        $this->deleteServiceData($serviceKey, $oldId);

        foreach ($service['child_rewrites'] as $rewrite) {
            $this->deleteChildren($rewrite['model'], $rewrite['column'], $oldId);
        }

        $old->delete();
    }

    /**
     * Extra cascade for nested relations not listed as direct cabinet_id children.
     */
    private function deleteServiceData(string $serviceKey, int $oldId): void
    {
        if ($serviceKey === 'feedbacks') {
            $reviewIds = Review::query()->where('cabinet_id', $oldId)->pluck('id');
            if ($reviewIds->isNotEmpty() && Schema::hasTable((new BotResponse)->getTable())) {
                BotResponse::query()->whereIn('review_id', $reviewIds)->delete();
            }
        }

        if ($serviceKey === 'profitability') {
            $reportIds = ProfitabilityReport::query()->where('cabinet_id', $oldId)->pluck('id');
            if ($reportIds->isNotEmpty() && Schema::hasTable((new ProfitabilityItem)->getTable())) {
                ProfitabilityItem::query()->whereIn('report_id', $reportIds)->delete();
            }
        }

        if ($serviceKey === 'ai_cabinet_analyzer') {
            $reportIds = AiCabinetAnalyzerReport::query()->where('cabinet_id', $oldId)->pluck('id');
            if ($reportIds->isNotEmpty() && Schema::hasTable((new AiCabinetAnalyzerAiAnalysis)->getTable())) {
                AiCabinetAnalyzerAiAnalysis::query()->whereIn('report_id', $reportIds)->delete();
            }
        }
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function deleteChildren(string $modelClass, string $column, int $oldId): void
    {
        /** @var Model $instance */
        $instance = new $modelClass;
        $table = $instance->getTable();
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        $modelClass::query()
            ->where($column, $oldId)
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($modelClass) {
                $ids = $rows->pluck('id')->all();
                if ($ids === []) {
                    return;
                }
                $modelClass::query()->whereIn('id', $ids)->delete();
            });
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function rewriteChildren(string $modelClass, string $column, int $oldId, int $newId): void
    {
        if ($oldId === $newId) {
            return;
        }

        /** @var Model $instance */
        $instance = new $modelClass;
        $table = $instance->getTable();
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        $modelClass::query()
            ->where($column, $oldId)
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($modelClass, $column, $newId) {
                $ids = $rows->pluck('id')->all();
                if ($ids === []) {
                    return;
                }
                $modelClass::query()->whereIn('id', $ids)->update([$column => $newId]);
            });
    }

    /**
     * @param  array{settings: ?string}  $service
     */
    private function migrateSettings(array $service, Model $old, int $newCabinetId): void
    {
        if (($service['settings'] ?? null) === 'feedbacks' && $old instanceof FeedbacksClients) {
            WbFeedbacksSettings::query()->updateOrCreate(
                ['cabinet_id' => $newCabinetId],
                [
                    'brands' => $old->getAttribute('brands'),
                    'bot_status' => (bool) $old->getAttribute('bot_status'),
                    'ai_status' => (bool) $old->getAttribute('ai_status'),
                    'ai_ratings' => $old->getAttribute('ai_ratings'),
                    'review_type' => $old->getAttribute('review_type'),
                ]
            );
        }

        if (($service['settings'] ?? null) === 'repricer' && $old instanceof RepricerCabinets) {
            $cabinet = WbCabinet::query()->find($newCabinetId);
            if ($cabinet) {
                $errorCode = $old->getAttribute('error_code');
                $errorMessage = $old->getAttribute('error_message');
                if ($errorCode !== null || $errorMessage !== null) {
                    $cabinet->forceFill([
                        'error_code' => $errorCode,
                        'error_message' => $errorMessage,
                    ])->save();
                }
            }
        }
    }
}
