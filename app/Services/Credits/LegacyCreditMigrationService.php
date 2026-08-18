<?php

namespace App\Services\Credits;

use App\Enums\Credits\CreditLedgerType;
use App\Exceptions\Credits\InvalidCreditOperationException;
use App\Models\Credits\CreditLedger;
use App\Models\Credits\CreditLegacyMigration;
use App\Models\Credits\CreditLegacyPlanMigration;
use App\Models\Subscribers\Subscribers;
use App\Models\Subscribers\SubscribersPlans;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Разовый перенос leftover AI-лимитов в раздельные балансы кредитов.
 */
class LegacyCreditMigrationService
{
    public function __construct(
        private readonly CreditBillingService $billing,
        private readonly LegacyAiLimitConverter $converter,
    ) {}

    public function isReady(): bool
    {
        return $this->billing->isReady()
            && $this->converter->isReady()
            && Schema::hasTable('credit_legacy_migrations')
            && Schema::hasTable('credit_legacy_plan_migrations')
            && Schema::hasColumn('credit_legacy_migrations', 'month_migrated_at')
            && Schema::hasColumn('subscribers_plans', 'credits_per_period')
            && Schema::hasColumn('subscribers_plans', 'limits_month')
            && Schema::hasColumn('subscribers_subscriptions', 'limits_month')
            && Schema::hasColumn('subscribers_subscriptions', 'extra_limits_month');
    }

    public function run(): void
    {
        if (! $this->isReady()) {
            throw new InvalidCreditOperationException(
                'Перенос leftover невозможен: нет каталога стоимости или таблиц кредитов.'
            );
        }

        $this->migratePlans();
        $this->migrateUsers();
    }

    public function migratePlans(): void
    {
        SubscribersPlans::query()
            ->orderBy('id')
            ->chunkById(100, function ($plans): void {
                foreach ($plans as $plan) {
                    $this->migratePlan($plan);
                }
            });
    }

    public function migrateUsers(): void
    {
        Subscribers::query()
            ->orderBy('id')
            ->chunkById(200, function ($subscribers): void {
                $subscribers->load(['user', 'subscriptions']);

                foreach ($subscribers as $subscriber) {
                    $this->migrateSubscriber($subscriber);
                }
            });
    }

    private function migratePlan(SubscribersPlans $plan): void
    {
        if (CreditLegacyPlanMigration::query()->where('plan_id', $plan->id)->exists()) {
            return;
        }

        $conversion = $this->converter->convert($this->jsonLimits($plan, 'limits_month'));

        if ($conversion->total < 1) {
            CreditLegacyPlanMigration::query()->create([
                'plan_id' => $plan->id,
                'source_month_limits' => $conversion->source,
                'quotes' => $conversion->unitPrices,
                'credits_written' => 0,
                'previous_credits_per_period' => (int) ($plan->credits_per_period ?? 0),
                'new_credits_per_period' => (int) ($plan->credits_per_period ?? 0),
                'ran_at' => now(),
            ]);

            return;
        }

        $previous = (int) ($plan->credits_per_period ?? 0);
        $plan->credits_per_period = $conversion->total;
        $plan->save();

        CreditLegacyPlanMigration::query()->create([
            'plan_id' => $plan->id,
            'source_month_limits' => $conversion->source,
            'quotes' => $conversion->unitPrices,
            'credits_written' => $conversion->total,
            'previous_credits_per_period' => $previous,
            'new_credits_per_period' => $conversion->total,
            'ran_at' => now(),
        ]);
    }

    private function migrateSubscriber(Subscribers $subscriber): void
    {
        $user = $subscriber->user;
        if (! $user instanceof User) {
            return;
        }

        $subscription = $this->pickSubscription($subscriber);
        if (! $subscription) {
            return;
        }

        DB::transaction(function () use ($user, $subscription): void {
            $row = CreditLegacyMigration::query()->firstOrNew(
                ['user_id' => $user->id],
                [
                    'source_extra_limits' => [],
                    'coefficients' => $this->converter->unitPrices(),
                    'purchased_credits' => 0,
                    'subscription_credits' => 0,
                ],
            );

            $month = $this->converter->convert($this->jsonLimits($subscription, 'limits_month'));
            $extra = $this->converter->convert($this->jsonLimits($subscription, 'extra_limits_month'));

            if ($row->coefficients === null || $row->coefficients === []) {
                $row->coefficients = $month->unitPrices;
            }

            $monthDone = $this->monthAlreadyMigrated($user, $row);
            $extraDone = $this->extraAlreadyMigrated($user, $row);

            if (! $monthDone && $month->total > 0) {
                $this->billing->addSubscription($user, $month->total, [
                    'type' => CreditLedgerType::Migration,
                    'idempotency_key' => 'migration:user:'.$user->id.':month',
                    'description' => 'Перенос месячных AI-лимитов в кредиты по тарифу',
                    'user_label' => 'Перенос месячных лимитов в кредиты по тарифу',
                    'related_type' => $subscription->getMorphClass(),
                    'related_id' => $subscription->id,
                ]);
            }

            if (! $monthDone) {
                $row->source_month_limits = $month->source;
                $row->subscription_credits = $month->total;
                $row->month_migrated_at = now();
            }

            if (! $extraDone && $extra->total > 0) {
                $this->billing->addPurchased($user, $extra->total, [
                    'type' => CreditLedgerType::Migration,
                    'idempotency_key' => 'migration:user:'.$user->id.':extra',
                    'description' => 'Перенос дополнительных AI-лимитов в купленные кредиты',
                    'user_label' => 'Перенос дополнительных лимитов в кредиты',
                    'related_type' => $subscription->getMorphClass(),
                    'related_id' => $subscription->id,
                ]);
            }

            if (! $extraDone) {
                $row->source_extra_limits = $extra->source;
                $row->purchased_credits = $extra->total;
                $row->extra_migrated_at = now();
                $row->ran_at = $row->ran_at ?? now();
            }

            $row->save();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonLimits(object $model, string $column): array
    {
        $table = $model->getTable();
        if (! Schema::hasColumn($table, $column)) {
            return [];
        }

        $raw = $model->getRawOriginal($column) ?? $model->getAttributes()[$column] ?? null;
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function pickSubscription(Subscribers $subscriber): ?SubscribersSubscriptions
    {
        $subscriptions = $subscriber->subscriptions;
        if ($subscriptions->isEmpty()) {
            return null;
        }

        return $subscriptions->firstWhere('status', 1)
            ?? $subscriptions->sortByDesc('id')->first();
    }

    private function monthAlreadyMigrated(User $user, CreditLegacyMigration $row): bool
    {
        if ($row->month_migrated_at !== null) {
            return true;
        }

        return CreditLedger::query()
            ->where('user_id', $user->id)
            ->where('idempotency_key', 'migration:user:'.$user->id.':month')
            ->exists();
    }

    private function extraAlreadyMigrated(User $user, CreditLegacyMigration $row): bool
    {
        if ($row->extra_migrated_at !== null) {
            return true;
        }

        if ($row->exists && $row->ran_at !== null && (int) $row->purchased_credits > 0) {
            return true;
        }

        return CreditLedger::query()
            ->where('user_id', $user->id)
            ->whereIn('idempotency_key', [
                'migration:user:'.$user->id,
                'migration:user:'.$user->id.':extra',
            ])
            ->exists();
    }
}
