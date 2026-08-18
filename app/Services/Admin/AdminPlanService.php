<?php

namespace App\Services\Admin;

use App\Models\Subscribers\Subscribers;
use App\Models\Subscribers\SubscribersPlans;
use App\Models\Subscribers\SubscribersSubscriptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

class AdminPlanService
{
    public function subscriberPermissionOptions(): array
    {
        return $this->subscriberPermissionsQuery()
            ->get(['id', 'name'])
            ->map(fn (Permission $permission) => [
                'id' => $permission->id,
                'name' => $permission->name,
            ])
            ->values()
            ->all();
    }

    public function subscriberPermissionsQuery(): Builder
    {
        return Permission::query()
            ->where(function (Builder $query) {
                $query->where('name', 'subscriber')
                    ->orWhere('name', 'like', 'subscriber %');
            })
            ->orderBy('name');
    }

    public function all(): Collection
    {
        return SubscribersPlans::query()->orderByDesc('id')->get();
    }

    public function available(): Collection
    {
        return SubscribersPlans::query()->where('status', 1)->orderBy('name')->get();
    }

    public function create(array $data): SubscribersPlans
    {
        $payload = [
            'name' => $data['name'],
            'price' => $data['price'],
            'duration' => $data['duration'],
            'description' => $data['description'] ?? '',
            'limits_plan' => $this->parseLimits($data['limits_plan'] ?? []),
            'permissions' => $this->normalizePermissions($data['permissions'] ?? []),
            'status' => $data['status'],
            'hidden' => $data['hidden'],
        ];

        if (Schema::hasColumn((new SubscribersPlans)->getTable(), 'credits_per_period')) {
            $payload['credits_per_period'] = $this->parseCreditsPerPeriod($data['credits_per_period'] ?? 0);
        }

        return SubscribersPlans::create($payload);
    }

    public function update(SubscribersPlans $plan, array $data): SubscribersPlans
    {
        $limitsPlan = $this->parseLimits($data['limits_plan'] ?? []);
        $permissions = $this->normalizePermissions($data['permissions'] ?? []);
        $payload = [
            'name' => $data['name'],
            'price' => $data['price'],
            'duration' => $data['duration'],
            'description' => $data['description'] ?? '',
            'limits_plan' => $limitsPlan,
        ];

        if (Schema::hasColumn((new SubscribersPlans)->getTable(), 'credits_per_period')) {
            $payload['credits_per_period'] = $this->parseCreditsPerPeriod($data['credits_per_period'] ?? 0);
        }

        $plan->update($payload + [
            'permissions' => $permissions,
            'status' => $data['status'],
            'hidden' => $data['hidden'],
        ]);

        $subscriptions = SubscribersSubscriptions::where('plan_id', $plan->id)->get();
        foreach ($subscriptions as $subscription) {
            $newLimitPlan = [];
            foreach ($limitsPlan as $key => $limit) {
                $newLimitPlan[$key] = $subscription->limits_plan[$key] ?? $limit;
            }

            $subscription->limits_plan = $newLimitPlan;
            $subscription->save();

            $subscriber = Subscribers::find($subscription->subscribers_id);
            $subscriber?->getUser()?->syncPermissions($permissions);
        }

        return $plan->fresh();
    }

    public function toggleStatus(SubscribersPlans $plan, bool $status): void
    {
        $plan->status = $status;
        $plan->save();
    }

    private function parseLimits(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $result = [];
        foreach (explode('|', $value) as $str) {
            if (! str_contains($str, ':')) {
                continue;
            }

            [$key, $item] = explode(':', $str, 2);
            $result[trim($key)] = trim($item);
        }

        return $result;
    }

    private function parseCreditsPerPeriod(mixed $value): int
    {
        return max(0, (int) $value);
    }

    /**
     * @param  mixed  $permissions
     * @return list<string>
     */
    private function normalizePermissions(mixed $permissions): array
    {
        if (! is_array($permissions)) {
            return [];
        }

        $allowed = $this->subscriberPermissionsQuery()
            ->pluck('name')
            ->all();

        $allowedSet = array_fill_keys($allowed, true);

        $normalized = [];
        foreach ($permissions as $permission) {
            if (! is_string($permission)) {
                continue;
            }

            $name = trim($permission);
            if ($name === '' || ! isset($allowedSet[$name])) {
                continue;
            }

            $normalized[] = $name;
        }

        return array_values(array_unique($normalized));
    }
}