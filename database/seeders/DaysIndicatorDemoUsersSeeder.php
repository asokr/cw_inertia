<?php

namespace Database\Seeders;

use App\Enums\SubscriptionsControlActionEnum;
use App\Models\Subscribers\Subscribers;
use App\Models\Subscribers\SubscribersPlans;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\Subscribers\SubscribersSubscriptionsControl;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Демо-учётки для ручной проверки индикатора дней в шапке панели.
 *
 * Запуск:
 *   php artisan db:seed --class=Database\\Seeders\\DaysIndicatorDemoUsersSeeder
 *
 * Пароль у всех: password
 *
 * | Email                         | Сценарий                                      | Ожидаемый UI              |
 * |-------------------------------|-----------------------------------------------|---------------------------|
 * | demo.days.hidden@cw.local     | +20 дней, 0 ₽                                 | Дней нет                  |
 * | demo.days.neutral@cw.local    | +10 дней, 0 ₽                                 | Дни, без urgent           |
 * | demo.days.urgent@cw.local     | +3 дня, 0 ₽                                   | Дни + urgent              |
 * | demo.days.funded@cw.local     | +3 дня, баланс ≥ price                        | Дни, без urgent           |
 * | demo.days.stopped@cw.local    | +3 дня, 0 ₽, STOP                             | Дни, без urgent           |
 * | demo.days.last@cw.local       | сегодня (0 дн.), 0 ₽                          | «Последний день» + urgent |
 * | demo.days.trial@cw.local      | trial plan_id=2, +3 дня                       | Индикатора нет (banner)   |
 */
class DaysIndicatorDemoUsersSeeder extends Seeder
{
    private const PASSWORD = 'password';

    private const TEST_PLAN_ID = 2;

    /**
     * @var list<array{
     *     email: string,
     *     name: string,
     *     days: int,
     *     balance: float|null,
     *     stop: bool,
     *     trial: bool,
     *     scenario: string
     * }>
     */
    private const ACCOUNTS = [
        [
            'email' => 'demo.days.hidden@cw.local',
            'name' => 'Demo Hidden Days',
            'days' => 20,
            'balance' => 0.0,
            'stop' => false,
            'trial' => false,
            'scenario' => '≥15 дней — индикатор скрыт',
        ],
        [
            'email' => 'demo.days.neutral@cw.local',
            'name' => 'Demo Neutral Days',
            'days' => 10,
            'balance' => 0.0,
            'stop' => false,
            'trial' => false,
            'scenario' => '10 дней, без средств — дни видны, без urgent',
        ],
        [
            'email' => 'demo.days.urgent@cw.local',
            'name' => 'Demo Urgent Days',
            'days' => 3,
            'balance' => 0.0,
            'stop' => false,
            'trial' => false,
            'scenario' => '3 дня, без средств — urgent',
        ],
        [
            'email' => 'demo.days.funded@cw.local',
            'name' => 'Demo Funded Days',
            'days' => 3,
            'balance' => null, // = plan price
            'stop' => false,
            'trial' => false,
            'scenario' => '3 дня, средств хватает — без urgent',
        ],
        [
            'email' => 'demo.days.stopped@cw.local',
            'name' => 'Demo Stopped Days',
            'days' => 3,
            'balance' => 0.0,
            'stop' => true,
            'trial' => false,
            'scenario' => '3 дня, STOP — дни видны, без urgent',
        ],
        [
            'email' => 'demo.days.last@cw.local',
            'name' => 'Demo Last Day',
            'days' => 0,
            'balance' => 0.0,
            'stop' => false,
            'trial' => false,
            'scenario' => 'Последний день, без средств — urgent',
        ],
        [
            'email' => 'demo.days.trial@cw.local',
            'name' => 'Demo Trial Days',
            'days' => 3,
            'balance' => 0.0,
            'stop' => false,
            'trial' => true,
            'scenario' => 'Trial — индикатор дней скрыт (promo banner)',
        ],
    ];

    public function run(): void
    {
        $this->ensureRolesAndPermissions();

        $paidPlan = $this->resolvePaidPlan();
        $trialPlan = $this->resolveTrialPlan();
        $paidPrice = (float) $paidPlan->price;

        $this->command?->info('Days indicator demo users (password: '.self::PASSWORD.')');
        $this->command?->info(str_repeat('-', 72));

        foreach (self::ACCOUNTS as $account) {
            $plan = $account['trial'] ? $trialPlan : $paidPlan;
            $targetBalance = $account['balance'] === null ? $paidPrice : (float) $account['balance'];

            $user = $this->upsertUser($account['email'], $account['name']);
            $subscriber = $this->upsertSubscriber($user);
            $subscription = $this->upsertSubscription(
                $subscriber,
                (int) $plan->id,
                $account['days']
            );

            $this->setBalanceTo($user, $targetBalance);
            $this->syncStopControl($subscription, $account['stop']);

            $this->command?->info(sprintf(
                '%-32s | %s',
                $account['email'],
                $account['scenario']
            ));
        }

        $this->command?->info(str_repeat('-', 72));
        $this->command?->info('Paid plan: #'.$paidPlan->id.' «'.$paidPlan->name.'» — '.$paidPrice.' ₽');
    }

    private function ensureRolesAndPermissions(): void
    {
        Role::firstOrCreate(['name' => 'Подписчик', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'subscriber', 'guard_name' => 'web']);
    }

    private function resolvePaidPlan(): SubscribersPlans
    {
        $plan = SubscribersPlans::query()
            ->where('status', 1)
            ->where('hidden', 0)
            ->where('id', '!=', self::TEST_PLAN_ID)
            ->where('price', '>', 0)
            ->orderBy('id')
            ->first();

        if ($plan) {
            return $plan;
        }

        return SubscribersPlans::query()->updateOrCreate(
            ['id' => 9001],
            [
                'name' => 'Demo Days',
                'description' => 'Демо-тариф для проверки индикатора дней',
                'price' => 1000,
                'duration' => 30,
                'limits_plan' => [],
                'limits_month' => [],
                'permissions' => ['subscriber'],
                'status' => 1,
                'hidden' => 0,
            ]
        );
    }

    private function resolveTrialPlan(): SubscribersPlans
    {
        return SubscribersPlans::query()->updateOrCreate(
            ['id' => self::TEST_PLAN_ID],
            [
                'name' => 'Тестовый',
                'description' => 'Пробный период',
                'price' => 0,
                'duration' => 7,
                'limits_plan' => [],
                'limits_month' => [],
                'permissions' => ['subscriber'],
                'status' => 1,
                'hidden' => 1,
            ]
        );
    }

    private function upsertUser(string $email, string $name): User
    {
        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'surname' => '',
                'password' => Hash::make(self::PASSWORD),
                'email_verified_at' => now(),
                'has_seen_tour' => true,
            ]
        );

        if (! $user->hasRole('Подписчик')) {
            $user->assignRole('Подписчик');
        }

        if (! $user->can('subscriber')) {
            $user->givePermissionTo('subscriber');
        }

        return $user->fresh();
    }

    private function upsertSubscriber(User $user): Subscribers
    {
        return Subscribers::query()->updateOrCreate(
            ['user_id' => $user->id],
            ['status' => 1]
        );
    }

    private function upsertSubscription(Subscribers $subscriber, int $planId, int $daysLeft): SubscribersSubscriptions
    {
        $endDate = $daysLeft === 0
            ? Carbon::now()->endOfDay()
            : Carbon::now()->startOfDay()->addDays($daysLeft)->setTime(23, 59, 59);

        $subscription = SubscribersSubscriptions::query()
            ->where('subscribers_id', $subscriber->id)
            ->first();

        $payload = [
            'plan_id' => $planId,
            'status' => 1,
            'start_date' => Carbon::now()->subDays(max(1, 30 - $daysLeft)),
            'end_date' => $endDate,
            'limits_plan' => [],
            'limits_month' => [],
            'extra_limits_month' => [],
        ];

        if ($subscription) {
            $subscription->fill($payload);
            $subscription->save();

            return $subscription->fresh();
        }

        return SubscribersSubscriptions::query()->create([
            'subscribers_id' => $subscriber->id,
            ...$payload,
        ]);
    }

    private function setBalanceTo(User $user, float $target): void
    {
        $user->refresh();
        $current = (float) $user->balance('RUB')->value->get();
        $diff = round($target - $current, 2);

        if (abs($diff) < 0.01) {
            return;
        }

        if ($diff > 0) {
            deposit($diff, 'RUB')
                ->to($user)
                ->overcharge()
                ->meta([
                    'description' => 'Days indicator demo seed top-up',
                    'operation' => 'days_indicator_demo_seed',
                ])
                ->commit();

            return;
        }

        // Снижаем баланс до целевого (idempotent re-seed)
        charge(abs($diff), 'RUB')
            ->from($user)
            ->overcharge()
            ->meta([
                'description' => 'Days indicator demo seed balance adjust',
                'operation' => 'days_indicator_demo_seed',
            ])
            ->commit();
    }

    private function syncStopControl(SubscribersSubscriptions $subscription, bool $shouldStop): void
    {
        SubscribersSubscriptionsControl::query()
            ->where('subscription_id', $subscription->id)
            ->where('action', SubscriptionsControlActionEnum::STOP)
            ->delete();

        if (! $shouldStop) {
            return;
        }

        SubscribersSubscriptionsControl::query()->create([
            'subscription_id' => $subscription->id,
            'action' => SubscriptionsControlActionEnum::STOP,
        ]);
    }
}
