<?php

namespace App\Services\Subscriber\Oz;

use App\Models\Subscribers\Oz\OzCabinet;
use App\Models\Subscribers\Oz\PriceCalc\OzPriceCalcFbo;
use App\Models\Subscribers\Oz\PriceCalc\OzPriceCalcFbs;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\User;
use App\Support\ToolLimits;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class OzCabinetService
{
    /**
     * @return Collection<int, OzCabinet>
     */
    public function listForUser(User $user): Collection
    {
        if (! Schema::hasTable('oz_cabinets')) {
            return new Collection;
        }

        return OzCabinet::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->get();
    }

    public function findOwned(User $user, int $cabinetId): ?OzCabinet
    {
        if (! Schema::hasTable('oz_cabinets')) {
            return null;
        }

        return OzCabinet::query()
            ->where('user_id', $user->id)
            ->where('id', $cabinetId)
            ->first();
    }

    /**
     * @param  array{name: string, client_id: string, apikey: string}  $data
     * @return array{cabinet: OzCabinet}
     */
    public function create(User $user, array $data, bool $enforceLimit = true): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $clientId = trim((string) ($data['client_id'] ?? ''));
        $apikey = trim((string) ($data['apikey'] ?? ''));

        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Укажите название кабинета.']);
        }

        if ($clientId === '') {
            throw ValidationException::withMessages(['client_id' => 'Укажите Client ID.']);
        }

        if ($apikey === '') {
            throw ValidationException::withMessages(['apikey' => 'Укажите API-ключ.']);
        }

        $exists = OzCabinet::query()
            ->where('user_id', $user->id)
            ->where('client_id', $clientId)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'client_id' => 'Такой Client ID уже добавлен для вашего аккаунта.',
            ]);
        }

        if ($enforceLimit) {
            $this->assertCanCreateCabinet($user);
        }

        $performanceClientId = array_key_exists('performance_client_id', $data)
            ? trim((string) $data['performance_client_id'])
            : '';
        $performanceClientSecret = array_key_exists('performance_client_secret', $data)
            ? trim((string) $data['performance_client_secret'])
            : '';

        $createPayload = [
            'user_id' => $user->id,
            'name' => $name,
            'client_id' => $clientId,
            'apikey' => $apikey,
        ];

        if ($performanceClientId !== '') {
            $createPayload['performance_client_id'] = $performanceClientId;
        }
        if ($performanceClientSecret !== '') {
            $createPayload['performance_client_secret'] = $performanceClientSecret;
        }

        $cabinet = OzCabinet::query()->create($createPayload);

        if (! $user->selected_oz_cabinet_id) {
            $user->forceFill(['selected_oz_cabinet_id' => $cabinet->id])->save();
        }

        return [
            'cabinet' => $cabinet->fresh(),
        ];
    }

    /**
     * @param  array{name?: string, client_id?: string, apikey?: string}  $data
     * @return array{cabinet: OzCabinet}
     */
    public function update(User $user, OzCabinet $cabinet, array $data): array
    {
        $this->assertOwnership($user, $cabinet);

        $name = array_key_exists('name', $data) ? trim((string) $data['name']) : $cabinet->name;
        $clientId = array_key_exists('client_id', $data) ? trim((string) $data['client_id']) : $cabinet->client_id;
        $apikey = array_key_exists('apikey', $data) ? trim((string) $data['apikey']) : null;

        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Укажите название кабинета.']);
        }

        if ($clientId === '') {
            throw ValidationException::withMessages(['client_id' => 'Укажите Client ID.']);
        }

        $exists = OzCabinet::query()
            ->where('user_id', $user->id)
            ->where('client_id', $clientId)
            ->where('id', '!=', $cabinet->id)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'client_id' => 'Такой Client ID уже добавлен для вашего аккаунта.',
            ]);
        }

        $payload = [
            'name' => $name,
            'client_id' => $clientId,
        ];

        if ($apikey !== null && $apikey !== '') {
            $payload['apikey'] = $apikey;
            $payload['last_sync_error'] = null;
        }

        if (array_key_exists('performance_client_id', $data)) {
            $perfId = trim((string) $data['performance_client_id']);
            $payload['performance_client_id'] = $perfId !== '' ? $perfId : null;
        }

        if (array_key_exists('performance_client_secret', $data)) {
            $perfSecret = trim((string) $data['performance_client_secret']);
            if ($perfSecret !== '') {
                $payload['performance_client_secret'] = $perfSecret;
            }
        }

        $cabinet->fill($payload)->save();

        return [
            'cabinet' => $cabinet->fresh(),
        ];
    }

    public function delete(User $user, OzCabinet $cabinet): void
    {
        $this->assertOwnership($user, $cabinet);

        DB::transaction(function () use ($user, $cabinet) {
            $cabinetId = $cabinet->id;

            if (Schema::hasTable('oz_price_calc_fbo')) {
                OzPriceCalcFbo::query()->where('cabinet_id', $cabinetId)->delete();
            }
            if (Schema::hasTable('oz_price_calc_fbs')) {
                OzPriceCalcFbs::query()->where('cabinet_id', $cabinetId)->delete();
            }

            $cabinet->delete();

            if ((int) $user->selected_oz_cabinet_id === $cabinetId) {
                $nextId = OzCabinet::query()
                    ->where('user_id', $user->id)
                    ->orderByDesc('id')
                    ->value('id');

                $user->forceFill(['selected_oz_cabinet_id' => $nextId])->save();
            }
        });
    }

    public function select(User $user, int $cabinetId): OzCabinet
    {
        $cabinet = $this->findOwned($user, $cabinetId);
        if (! $cabinet) {
            throw ValidationException::withMessages([
                'cabinet_id' => 'Кабинет не найден.',
            ]);
        }

        $user->forceFill(['selected_oz_cabinet_id' => $cabinet->id])->save();

        return $cabinet;
    }

    public function selectedFor(User $user): ?OzCabinet
    {
        if (! Schema::hasTable('oz_cabinets')) {
            return null;
        }

        $selectedId = (int) ($user->selected_oz_cabinet_id ?? 0);
        if ($selectedId > 0) {
            $cabinet = $this->findOwned($user, $selectedId);
            if ($cabinet) {
                return $cabinet;
            }
        }

        $first = OzCabinet::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->first();

        if (
            $first
            && Schema::hasColumn('users', 'selected_oz_cabinet_id')
            && (int) $user->selected_oz_cabinet_id !== (int) $first->id
        ) {
            $user->forceFill(['selected_oz_cabinet_id' => $first->id])->save();
        }

        return $first;
    }

    /**
     * @return array{id: int, name: string, client_id: string}|null
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
            'client_id' => (string) $cabinet->client_id,
            'performance_client_id' => $cabinet->performance_client_id
                ? (string) $cabinet->performance_client_id
                : null,
            'has_performance_credentials' => filled($cabinet->performance_client_id)
                && filled($cabinet->performance_client_secret),
        ];
    }

    /**
     * @return list<array{id: int, name: string, client_id: string, performance_client_id: ?string, has_performance_credentials: bool}>
     */
    public function listSummaries(User $user): array
    {
        return $this->listForUser($user)
            ->map(fn (OzCabinet $cabinet) => [
                'id' => (int) $cabinet->id,
                'name' => (string) $cabinet->name,
                'client_id' => (string) $cabinet->client_id,
                'performance_client_id' => $cabinet->performance_client_id
                    ? (string) $cabinet->performance_client_id
                    : null,
                'has_performance_credentials' => filled($cabinet->performance_client_id)
                    && filled($cabinet->performance_client_secret),
            ])
            ->values()
            ->all();
    }

    private function assertOwnership(User $user, OzCabinet $cabinet): void
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

        if (! array_key_exists('oz_cabinets', $limits)) {
            return;
        }

        $currentCount = OzCabinet::query()->where('user_id', $user->id)->count();
        if ($currentCount >= (int) $limits['oz_cabinets']) {
            throw ValidationException::withMessages([
                'name' => 'Вы исчерпали лимит на количество кабинетов Ozon.',
            ]);
        }

        if (ToolLimits::canUsePlanLimit($user, $limits, 'oz_cabinets')) {
            $updated = ToolLimits::applyPlanLimitConsumption($user, $limits, 'oz_cabinets');
            if (is_array($updated)) {
                $subscription->limits_plan = $updated;
                $subscription->save();
            }
        }
    }
}
