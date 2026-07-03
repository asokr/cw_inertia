<?php

namespace App\Services\Admin;

use App\Models\Subscribers\Subscribers;
use App\Models\Subscribers\SubscribersPlans;
use App\Models\Subscribers\SubscribersSubscriptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
        return SubscribersPlans::create([
            'name' => $data['name'],
            'price' => $data['price'],
            'duration' => $data['duration'],
            'description' => $data['description'] ?? '',
            'limits_plan' => $this->parseLimits($data['limits_plan'] ?? []),
            'limits_month' => $this->parseLimits($data['limits_month'] ?? []),
            'permissions' => $data['permissions'],
            'status' => $data['status'],
            'hidden' => $data['hidden'],
        ]);
    }

    public function update(SubscribersPlans $plan, array $data): SubscribersPlans
    {
        $limitsPlan = $this->parseLimits($data['limits_plan'] ?? []);
        $limitsMonth = $this->parseLimits($data['limits_month'] ?? []);

        $plan->update([
            'name' => $data['name'],
            'price' => $data['price'],
            'duration' => $data['duration'],
            'description' => $data['description'] ?? '',
            'limits_plan' => $limitsPlan,
            'limits_month' => $limitsMonth,
            'permissions' => $data['permissions'],
            'status' => $data['status'],
            'hidden' => $data['hidden'],
        ]);

        $subscriptions = SubscribersSubscriptions::where('plan_id', $plan->id)->get();
        foreach ($subscriptions as $subscription) {
            $newLimitMonth = [];
            foreach ($limitsMonth as $key => $limit) {
                $newLimitMonth[$key] = $subscription->limits_month[$key] ?? $limit;
            }

            $newLimitPlan = [];
            foreach ($limitsPlan as $key => $limit) {
                $newLimitPlan[$key] = $subscription->limits_plan[$key] ?? $limit;
            }

            $subscription->limits_plan = $newLimitPlan;
            $subscription->limits_month = $newLimitMonth;
            $subscription->save();

            $subscriber = Subscribers::find($subscription->subscribers_id);
            $subscriber?->getUser()?->syncPermissions($plan->permissions);
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
}