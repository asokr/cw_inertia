<?php

namespace App\Services\Subscriber\Wb;

use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\Subscribers\Wb\Feedbacks\WbFeedbacksSettings;
use App\Models\Subscribers\Wb\WbCabinet;
use App\Models\User;
use App\Services\Wb\WbCabinetApiKeyValidator;
use App\Support\ToolLimits;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class WbCabinetService
{
    public const API_KEY_WARNING = 'Для корректной работы всех инструментов платформы необходимо использовать персональный API-ключ Wildberries с полным набором разрешений. Использование тестового ключа или ключа с ограниченными правами может привести к некорректной работе отдельных сервисов. В этом случае корректная работа платформы не гарантируется.';

    public function __construct(
        private readonly WbCabinetApiKeyValidator $apiKeyValidator,
    ) {
    }

    /**
     * @return Collection<int, WbCabinet>
     */
    public function listForUser(User $user): Collection
    {
        if (! Schema::hasTable('wb_cabinets')) {
            return new Collection;
        }

        return WbCabinet::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->get();
    }

    public function findOwned(User $user, int $cabinetId): ?WbCabinet
    {
        if (! Schema::hasTable('wb_cabinets')) {
            return null;
        }

        return WbCabinet::query()
            ->where('user_id', $user->id)
            ->where('id', $cabinetId)
            ->first();
    }

    /**
     * @param  array{name: string, apikey: string}  $data
     * @return array{cabinet: WbCabinet, permission_warnings: list<string>}
     */
    public function create(User $user, array $data, bool $enforceLimit = true): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $apikey = trim((string) ($data['apikey'] ?? ''));

        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Укажите название кабинета.']);
        }

        if ($apikey === '') {
            throw ValidationException::withMessages(['apikey' => 'Укажите API-ключ.']);
        }

        $validation = $this->apiKeyValidator->validate($apikey);
        if (! $validation['valid']) {
            throw ValidationException::withMessages([
                'apikey' => $validation['message'] ?? 'API-ключ не прошёл проверку.',
            ]);
        }

        $hash = $this->hashApiKey($apikey);
        if (WbCabinet::query()->where('api_key_hash', $hash)->exists()) {
            throw ValidationException::withMessages([
                'apikey' => 'Кабинет с таким API-ключом уже есть в системе.',
            ]);
        }

        if ($enforceLimit) {
            $this->assertCanCreateCabinet($user);
        }

        $cabinet = WbCabinet::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'apikey' => $apikey,
            'api_key_hash' => $hash,
        ]);

        WbFeedbacksSettings::query()->firstOrCreate(
            ['cabinet_id' => $cabinet->id],
            [
                'brands' => null,
                'bot_status' => false,
                'ai_status' => false,
                'ai_ratings' => null,
                'review_type' => null,
            ]
        );

        if (! $user->selected_wb_cabinet_id) {
            $user->forceFill(['selected_wb_cabinet_id' => $cabinet->id])->save();
        }

        return [
            'cabinet' => $cabinet->fresh(),
            'permission_warnings' => $validation['permission_warnings'],
        ];
    }

    /**
     * @param  array{name?: string, apikey?: string}  $data
     * @return array{cabinet: WbCabinet, permission_warnings: list<string>}
     */
    public function update(User $user, WbCabinet $cabinet, array $data): array
    {
        $this->assertOwnership($user, $cabinet);

        $name = array_key_exists('name', $data) ? trim((string) $data['name']) : $cabinet->name;
        $apikey = array_key_exists('apikey', $data) ? trim((string) $data['apikey']) : null;

        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Укажите название кабинета.']);
        }

        $permissionWarnings = [];

        $payload = ['name' => $name];

        if ($apikey !== null && $apikey !== '') {
            $validation = $this->apiKeyValidator->validate($apikey);
            if (! $validation['valid']) {
                throw ValidationException::withMessages([
                    'apikey' => $validation['message'] ?? 'API-ключ не прошёл проверку.',
                ]);
            }

            $hash = $this->hashApiKey($apikey);
            $exists = WbCabinet::query()
                ->where('api_key_hash', $hash)
                ->where('id', '!=', $cabinet->id)
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages([
                    'apikey' => 'Кабинет с таким API-ключом уже есть в системе.',
                ]);
            }

            $payload['apikey'] = $apikey;
            $payload['api_key_hash'] = $hash;
            $payload['error_code'] = null;
            $payload['error_message'] = null;
            $permissionWarnings = $validation['permission_warnings'];
        }

        $cabinet->fill($payload)->save();

        return [
            'cabinet' => $cabinet->fresh(),
            'permission_warnings' => $permissionWarnings,
        ];
    }

    public function delete(User $user, WbCabinet $cabinet): void
    {
        $this->assertOwnership($user, $cabinet);

        DB::transaction(function () use ($user, $cabinet) {
            $cabinetId = $cabinet->id;
            $cabinet->delete();

            if ((int) $user->selected_wb_cabinet_id === $cabinetId) {
                $nextId = WbCabinet::query()
                    ->where('user_id', $user->id)
                    ->orderByDesc('id')
                    ->value('id');

                $user->forceFill(['selected_wb_cabinet_id' => $nextId])->save();
            }
        });
    }

    public function select(User $user, int $cabinetId): WbCabinet
    {
        $cabinet = $this->findOwned($user, $cabinetId);
        if (! $cabinet) {
            throw ValidationException::withMessages([
                'cabinet_id' => 'Кабинет не найден.',
            ]);
        }

        $user->forceFill(['selected_wb_cabinet_id' => $cabinet->id])->save();

        return $cabinet;
    }

    public function selectedFor(User $user): ?WbCabinet
    {
        if (! Schema::hasTable('wb_cabinets')) {
            return null;
        }

        $selectedId = (int) ($user->selected_wb_cabinet_id ?? 0);
        if ($selectedId > 0) {
            $cabinet = $this->findOwned($user, $selectedId);
            if ($cabinet) {
                return $cabinet;
            }
        }

        $first = WbCabinet::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->first();

        if (
            $first
            && Schema::hasColumn('users', 'selected_wb_cabinet_id')
            && (int) $user->selected_wb_cabinet_id !== (int) $first->id
        ) {
            $user->forceFill(['selected_wb_cabinet_id' => $first->id])->save();
        }

        return $first;
    }

    /**
     * @return array{id: int, name: string}|null
     */
    public function selectedSummary(User $user): ?array
    {
        $cabinet = $this->selectedFor($user);
        if (! $cabinet) {
            return null;
        }

        return [
            'id' => (int) $cabinet->id,
            'name' => (string) $cabinet->name,
        ];
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function listSummaries(User $user): array
    {
        return $this->listForUser($user)
            ->map(fn (WbCabinet $cabinet) => [
                'id' => (int) $cabinet->id,
                'name' => (string) $cabinet->name,
            ])
            ->values()
            ->all();
    }

    public function hashApiKey(string $apiKey): string
    {
        return hash('sha256', trim($apiKey));
    }

    private function assertOwnership(User $user, WbCabinet $cabinet): void
    {
        if ((int) $cabinet->user_id !== (int) $user->id) {
            abort(404);
        }
    }

    private function assertCanCreateCabinet(User $user): void
    {
        $subscriber = $user->subscriber;
        if (! $subscriber) {
            return;
        }

        $subscription = SubscribersSubscriptions::query()
            ->where('subscribers_id', $subscriber->id)
            ->first();

        if (! $subscription) {
            return;
        }

        $limits = is_array($subscription->limits_plan) ? $subscription->limits_plan : [];

        // Prefer unified limit; fall back to max of legacy keys for transitional plans.
        if (! array_key_exists('wb_cabinets', $limits)) {
            $legacy = [];
            foreach (['feedbacks_clients', 'price_calc_clients'] as $legacyKey) {
                if (isset($limits[$legacyKey])) {
                    $legacy[] = (int) $limits[$legacyKey];
                }
            }
            if ($legacy !== []) {
                $limits['wb_cabinets'] = max($legacy);
            }
        }

        if (! array_key_exists('wb_cabinets', $limits)) {
            return;
        }

        $currentCount = WbCabinet::query()->where('user_id', $user->id)->count();
        if ($currentCount >= (int) $limits['wb_cabinets']) {
            throw ValidationException::withMessages([
                'name' => 'Вы исчерпали лимит на количество кабинетов Wildberries.',
            ]);
        }

        // Consume unified limit when present as plan counter.
        if (ToolLimits::canUsePlanLimit($user, $limits, 'wb_cabinets')) {
            $updated = ToolLimits::applyPlanLimitConsumption($user, $limits, 'wb_cabinets');
            if (is_array($updated)) {
                $subscription->limits_plan = $updated;
                $subscription->save();
            }
        }
    }
}
