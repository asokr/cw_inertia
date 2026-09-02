<?php

namespace Tests\Unit\Credits;

use App\Enums\Credits\CreditHoldStatus;
use App\Enums\Credits\CreditLedgerType;
use App\Exceptions\Credits\InsufficientCreditsException;
use App\Exceptions\Credits\InvalidCreditOperationException;
use App\Models\Credits\CreditAccount;
use App\Models\Credits\CreditHold;
use App\Models\Subscribers\Subscribers;
use App\Models\Subscribers\SubscribersPlans;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\User;
use App\Services\Credits\CreditBillingService;
use App\Notifications\SubscriptionCreditsEndedNotification;
use App\Services\Credits\CreditSpendRequest;
use App\Support\HomeRedirect;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Mockery;
use Tests\Support\CreatesCreditBillingSchema;
use Tests\TestCase;

class CreditBillingServiceTest extends TestCase
{
    use CreatesCreditBillingSchema;

    private CreditBillingService $billing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setupCreditBillingSchema();
        $this->billing = app(CreditBillingService::class);
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_spend_uses_subscription_credits_first(): void
    {
        $user = $this->makeUser();
        $this->seedBalances($user, subscription: 20, purchased: 100);

        $ledger = $this->billing->spend($user, new CreditSpendRequest(
            amount: 40,
            serviceCode: 'generate_text',
            idempotencyKey: 'spend-1',
        ));

        $account = CreditAccount::query()->where('user_id', $user->id)->first();
        $this->assertSame(0, $account->subscription_balance);
        $this->assertSame(80, $account->purchased_balance);
        $this->assertSame(20, $ledger->source_split['subscription']);
        $this->assertSame(20, $ledger->source_split['purchased']);
        $this->assertSame(80, $ledger->available_after);
    }

    public function test_spend_keeps_purchased_when_subscription_covers_amount(): void
    {
        $user = $this->makeUser();
        $this->seedBalances($user, subscription: 100, purchased: 100);

        $this->billing->spend($user, new CreditSpendRequest(
            amount: 40,
            serviceCode: 'generate_text',
            idempotencyKey: 'spend-2',
        ));

        $account = CreditAccount::query()->where('user_id', $user->id)->first();
        $this->assertSame(60, $account->subscription_balance);
        $this->assertSame(100, $account->purchased_balance);
    }

    public function test_spend_fails_when_not_enough_credits(): void
    {
        $user = $this->makeUser();
        $this->seedBalances($user, subscription: 5, purchased: 5);

        try {
            $this->billing->spend($user, new CreditSpendRequest(
                amount: 20,
                serviceCode: 'generate_text',
                idempotencyKey: 'spend-fail',
            ));
            $this->fail('Ожидалось InsufficientCreditsException');
        } catch (InsufficientCreditsException $exception) {
            $this->assertSame(20, $exception->required);
            $this->assertSame(10, $exception->available);
            $this->assertSame('Недостаточно кредитов', $exception->getMessage());
        }

        $account = CreditAccount::query()->where('user_id', $user->id)->first();
        $this->assertSame(5, $account->subscription_balance);
        $this->assertSame(5, $account->purchased_balance);
        $this->assertSame(0, $account->ledger()->count());
    }

    public function test_spend_is_idempotent(): void
    {
        $user = $this->makeUser();
        $this->seedBalances($user, subscription: 50, purchased: 0);

        $first = $this->billing->spend($user, new CreditSpendRequest(
            amount: 10,
            serviceCode: 'generate_text',
            idempotencyKey: 'same-key',
        ));
        $second = $this->billing->spend($user, new CreditSpendRequest(
            amount: 10,
            serviceCode: 'generate_text',
            idempotencyKey: 'same-key',
        ));

        $this->assertSame($first->id, $second->id);
        $account = CreditAccount::query()->where('user_id', $user->id)->first();
        $this->assertSame(40, $account->subscription_balance);
        $this->assertSame(1, $account->ledger()->count());
    }

    public function test_grant_replaces_subscription_balance_and_keeps_purchased(): void
    {
        $user = $this->makeUserWithSubscription(300);
        $this->seedBalances($user, subscription: 27, purchased: 180);
        $subscription = $user->getSubscriptions();
        $plan = $subscription->getPlan();

        $this->billing->grantPeriod($user, $subscription, $plan);

        $account = CreditAccount::query()->where('user_id', $user->id)->first();
        $this->assertSame(300, $account->subscription_balance);
        $this->assertSame(180, $account->purchased_balance);
    }

    public function test_upgrade_adds_plan_credits_to_remaining_subscription(): void
    {
        $user = $this->makeUserWithSubscription(300);
        $this->seedBalances($user, subscription: 40, purchased: 80);
        $subscription = $user->getSubscriptions();
        $plan = $subscription->getPlan();

        $this->billing->grantUpgrade($user, $subscription, $plan);

        $account = CreditAccount::query()->where('user_id', $user->id)->first();
        $this->assertSame(340, $account->subscription_balance);
        $this->assertSame(80, $account->purchased_balance);

        $this->billing->grantUpgrade($user, $subscription, $plan);
        $account->refresh();
        $this->assertSame(340, $account->subscription_balance);
        $this->assertSame(1, $account->ledger()->where('type', CreditLedgerType::GrantSubscription)->count());
    }

    public function test_grant_is_idempotent_for_same_period(): void
    {
        $user = $this->makeUserWithSubscription(300);
        $subscription = $user->getSubscriptions();
        $plan = $subscription->getPlan();

        $this->billing->grantPeriod($user, $subscription, $plan);
        $this->billing->grantPeriod($user, $subscription, $plan);

        $account = CreditAccount::query()->where('user_id', $user->id)->first();
        $this->assertSame(300, $account->subscription_balance);
        $this->assertSame(1, $account->ledger()->where('type', CreditLedgerType::GrantSubscription)->count());
    }

    public function test_add_subscription_increases_only_subscription_balance(): void
    {
        $user = $this->makeUser();
        $this->seedBalances($user, subscription: 100, purchased: 50);

        $this->billing->addSubscription($user, 20, [
            'idempotency_key' => 'mig-month-1',
            'type' => CreditLedgerType::Migration,
        ]);

        $account = CreditAccount::query()->where('user_id', $user->id)->first();
        $this->assertSame(120, $account->subscription_balance);
        $this->assertSame(50, $account->purchased_balance);
    }

    public function test_add_purchased_increases_only_purchased_balance(): void
    {
        $user = $this->makeUser();
        $this->seedBalances($user, subscription: 100, purchased: 50);

        $this->billing->addPurchased($user, 300, [
            'idempotency_key' => 'buy-1',
        ]);

        $account = CreditAccount::query()->where('user_id', $user->id)->first();
        $this->assertSame(100, $account->subscription_balance);
        $this->assertSame(350, $account->purchased_balance);
    }

    public function test_hold_capture_and_release(): void
    {
        $user = $this->makeUser();
        $this->seedBalances($user, subscription: 30, purchased: 20);

        $hold = $this->billing->reserve($user, new CreditSpendRequest(
            amount: 40,
            serviceCode: 'generate_video',
            idempotencyKey: 'hold-1',
            operationParams: ['duration' => 10, 'resolution' => '720p'],
        ));

        $account = CreditAccount::query()->where('user_id', $user->id)->first();
        $this->assertSame(30, $account->subscription_balance);
        $this->assertSame(20, $account->purchased_balance);
        $this->assertSame(30, $account->subscription_held);
        $this->assertSame(10, $account->purchased_held);
        $this->assertSame(10, $account->available());

        $this->billing->capture($hold);

        $account->refresh();
        $this->assertSame(0, $account->subscription_balance);
        $this->assertSame(10, $account->purchased_balance);
        $this->assertSame(0, $account->subscription_held);
        $this->assertSame(0, $account->purchased_held);
        $this->assertSame(CreditHoldStatus::Captured, $hold->fresh()->status);
    }

    public function test_capture_open_hold_is_idempotent(): void
    {
        $user = $this->makeUser();
        $this->seedBalances($user, subscription: 20, purchased: 0);

        $this->billing->reserve($user, new CreditSpendRequest(
            amount: 10,
            serviceCode: 'wb_ai_cabinet_analyzer',
            idempotencyKey: 'open-hold-1',
            operationParams: ['user_label' => 'ИИ-анализ кабинета WB: Тест'],
        ));

        $first = $this->billing->captureOpenHold('open-hold-1');
        $second = $this->billing->captureOpenHold('open-hold-1');

        $this->assertNotNull($first);
        $this->assertSame($first->id, $second?->id);
        $this->assertSame('ИИ-анализ кабинета WB: Тест', $first->user_label);
        $this->assertNull($this->billing->captureOpenHold('missing-key'));
    }

    public function test_settle_open_hold_shrinks_to_actual_amount(): void
    {
        $user = $this->makeUser();
        $this->seedBalances($user, subscription: 30, purchased: 20);

        $this->billing->reserve($user, new CreditSpendRequest(
            amount: 20,
            serviceCode: 'wb_ai_cabinet_analyzer',
            idempotencyKey: 'settle-shrink',
            operationParams: ['user_label' => 'ИИ-анализ кабинета WB: Тест'],
        ));

        $ledger = $this->billing->settleOpenHold('settle-shrink', 8);

        $this->assertSame(8, $ledger?->amount);
        $account = CreditAccount::query()->where('user_id', $user->id)->first();
        $this->assertSame(42, $account->available());
        $this->assertSame(0, $account->subscription_held + $account->purchased_held);
        $this->assertSame(CreditHoldStatus::Captured, CreditHold::query()->where('idempotency_key', 'settle-shrink')->first()?->status);
        $this->assertSame($ledger?->id, $this->billing->settleOpenHold('settle-shrink', 8)?->id);
    }

    public function test_settle_open_hold_grows_when_user_has_extra_credits(): void
    {
        $user = $this->makeUser();
        $this->seedBalances($user, subscription: 10, purchased: 10);

        $this->billing->reserve($user, new CreditSpendRequest(
            amount: 8,
            serviceCode: 'wb_ai_cabinet_analyzer',
            idempotencyKey: 'settle-grow',
        ));

        $ledger = $this->billing->settleOpenHold('settle-grow', 15);

        $this->assertSame(15, $ledger?->amount);
        $account = CreditAccount::query()->where('user_id', $user->id)->first();
        $this->assertSame(5, $account->available());
    }

    public function test_settle_open_hold_caps_when_extra_credits_missing(): void
    {
        $user = $this->makeUser();
        $this->seedBalances($user, subscription: 10, purchased: 0);

        $this->billing->reserve($user, new CreditSpendRequest(
            amount: 8,
            serviceCode: 'wb_ai_cabinet_analyzer',
            idempotencyKey: 'settle-under',
        ));

        $ledger = $this->billing->settleOpenHold('settle-under', 20, ['requested' => 20]);

        $this->assertSame(8, $ledger?->amount);
        $params = $ledger?->operation_params ?? [];
        $this->assertTrue((bool) ($params['undercharged'] ?? false));
        $account = CreditAccount::query()->where('user_id', $user->id)->first();
        $this->assertSame(2, $account->available());
    }

    public function test_release_returns_held_credits(): void
    {
        $user = $this->makeUser();
        $this->seedBalances($user, subscription: 20, purchased: 10);

        $hold = $this->billing->reserve($user, new CreditSpendRequest(
            amount: 15,
            serviceCode: 'generate_video',
            idempotencyKey: 'hold-release',
        ));

        $this->billing->release($hold);

        $account = CreditAccount::query()->where('user_id', $user->id)->first();
        $this->assertSame(20, $account->subscription_balance);
        $this->assertSame(10, $account->purchased_balance);
        $this->assertSame(0, $account->subscription_held);
        $this->assertSame(0, $account->purchased_held);
        $this->assertSame(30, $account->available());
    }

    public function test_adjust_cannot_go_negative(): void
    {
        $user = $this->makeUser();
        $admin = $this->makeUser('admin@example.com');
        $this->seedBalances($user, subscription: 10, purchased: 5);

        $this->expectException(InvalidCreditOperationException::class);
        $this->billing->adjust($user, -20, 0, 'тест', $admin);
    }

    public function test_adjust_changes_balances(): void
    {
        $user = $this->makeUser();
        $admin = $this->makeUser('admin@example.com');
        $this->seedBalances($user, subscription: 10, purchased: 5);

        $this->billing->adjust($user, 15, -2, 'Компенсация', $admin);

        $account = CreditAccount::query()->where('user_id', $user->id)->first();
        $this->assertSame(25, $account->subscription_balance);
        $this->assertSame(3, $account->purchased_balance);
    }

    public function test_refund_returns_to_original_sources(): void
    {
        $user = $this->makeUser();
        $this->seedBalances($user, subscription: 10, purchased: 10);

        $spend = $this->billing->spend($user, new CreditSpendRequest(
            amount: 15,
            serviceCode: 'generate_text',
            idempotencyKey: 'spend-refund',
        ));

        $this->billing->refund($spend, 'Ошибка генерации');

        $account = CreditAccount::query()->where('user_id', $user->id)->first();
        $this->assertSame(10, $account->subscription_balance);
        $this->assertSame(10, $account->purchased_balance);
    }

    public function test_expired_holds_are_released(): void
    {
        $user = $this->makeUser();
        $this->seedBalances($user, subscription: 20, purchased: 0);

        $this->billing->reserve(
            $user,
            new CreditSpendRequest(
                amount: 5,
                serviceCode: 'generate_video',
                idempotencyKey: 'hold-exp',
            ),
            Carbon::now()->subMinute(),
        );

        $released = $this->billing->releaseExpiredHolds();

        $this->assertSame(1, $released);
        $account = CreditAccount::query()->where('user_id', $user->id)->first();
        $this->assertSame(20, $account->available());
        $this->assertSame(CreditHoldStatus::Expired, CreditHold::query()->first()->status);
    }

    public function test_admin_bypass_does_not_change_balance(): void
    {
        $admin = Mockery::mock(User::class)->makePartial();
        $admin->shouldReceive('hasRole')->andReturnUsing(function (mixed $role): bool {
            if ($role === 'Подписчик') {
                return false;
            }

            $names = is_array($role) ? $role : [$role];

            return in_array('Супер-Админ', $names, true)
                || in_array('super-admin', $names, true);
        });
        $admin->shouldReceive('getAllPermissions')->andReturn(collect());
        $admin->id = $this->makeUser('admin-bypass@example.com')->id;

        $this->assertTrue(HomeRedirect::isAdmin($admin));

        $this->seedBalances($admin, subscription: 5, purchased: 0);

        $this->billing->spend($admin, new CreditSpendRequest(
            amount: 5,
            serviceCode: 'generate_text',
            idempotencyKey: 'admin-spend',
        ));

        $account = CreditAccount::query()->where('user_id', $admin->id)->first();
        $this->assertSame(5, $account->subscription_balance);
    }

    public function test_subscriber_with_admin_role_is_charged(): void
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('hasRole')->andReturnUsing(function (mixed $role): bool {
            if ($role === 'Подписчик') {
                return true;
            }

            $names = is_array($role) ? $role : [$role];

            return in_array('Супер-Админ', $names, true)
                || in_array('super-admin', $names, true);
        });
        $user->shouldReceive('getAllPermissions')->andReturn(collect());
        $user->id = $this->makeUser('subscriber-admin@example.com')->id;

        $this->seedBalances($user, subscription: 20, purchased: 0);

        $this->billing->spend($user, new CreditSpendRequest(
            amount: 10,
            serviceCode: 'oz_ai_cabinet_analyzer',
            idempotencyKey: 'dual-role-spend',
        ));

        $account = CreditAccount::query()->where('user_id', $user->id)->first();
        $this->assertSame(10, $account->subscription_balance);
        $this->assertSame(10, $account->available());
    }

    public function test_spend_does_not_notify_while_purchased_credits_remain(): void
    {
        $user = $this->makeUser();
        $this->seedBalances($user, subscription: 5, purchased: 10);
        CreditAccount::query()->where('user_id', $user->id)->update([
            'last_granted_period_key' => 'subscription:1:period:2026-01-01',
        ]);

        $this->billing->spend($user, new CreditSpendRequest(
            amount: 5,
            serviceCode: 'generate_text',
            idempotencyKey: 'end-tariff-only',
        ));

        Notification::assertNothingSent();
    }

    public function test_spend_notifies_once_when_total_credits_end(): void
    {
        $user = $this->makeUser();
        $this->seedBalances($user, subscription: 5, purchased: 10);
        CreditAccount::query()->where('user_id', $user->id)->update([
            'last_granted_period_key' => 'subscription:1:period:2026-01-01',
        ]);

        $this->billing->spend($user, new CreditSpendRequest(
            amount: 5,
            serviceCode: 'generate_text',
            idempotencyKey: 'end-1',
        ));
        Notification::assertNothingSent();

        $this->billing->spend($user, new CreditSpendRequest(
            amount: 10,
            serviceCode: 'generate_text',
            idempotencyKey: 'end-2',
        ));
        Notification::assertSentTo($user, SubscriptionCreditsEndedNotification::class);

        $this->billing->addPurchased($user, 4, [
            'idempotency_key' => 'end-topup',
        ]);
        $this->billing->spend($user, new CreditSpendRequest(
            amount: 4,
            serviceCode: 'generate_text',
            idempotencyKey: 'end-3',
        ));
        Notification::assertSentToTimes($user, SubscriptionCreditsEndedNotification::class, 1);
    }

    public function test_spend_does_not_notify_when_credits_remain(): void
    {
        $user = $this->makeUser();
        $this->seedBalances($user, subscription: 10, purchased: 0);

        $this->billing->spend($user, new CreditSpendRequest(
            amount: 4,
            serviceCode: 'generate_text',
            idempotencyKey: 'remain-1',
        ));

        Notification::assertNothingSent();
    }

    private function makeUser(string $email = 'user@example.com'): User
    {
        return User::query()->create([
            'name' => 'Тест',
            'email' => $email,
            'password' => Hash::make('password'),
        ]);
    }

    private function makeUserWithSubscription(int $creditsPerPeriod): User
    {
        $user = $this->makeUser();
        $subscriber = Subscribers::query()->create([
            'user_id' => $user->id,
            'status' => 1,
        ]);
        $plan = SubscribersPlans::query()->create([
            'name' => 'AI',
            'price' => 1000,
            'duration' => 30,
            'description' => '',
            'limits_plan' => [],
            'credits_per_period' => $creditsPerPeriod,
            'permissions' => ['subscriber'],
            'status' => 1,
            'hidden' => 0,
        ]);
        SubscribersSubscriptions::query()->create([
            'subscribers_id' => $subscriber->id,
            'plan_id' => $plan->id,
            'limits_plan' => [],
            'start_date' => Carbon::now(),
            'end_date' => Carbon::now()->addDays(30),
            'status' => 1,
        ]);

        return $user->fresh();
    }

    private function seedBalances(User $user, int $subscription, int $purchased): void
    {
        CreditAccount::query()->create([
            'user_id' => $user->id,
            'subscription_balance' => $subscription,
            'purchased_balance' => $purchased,
            'subscription_held' => 0,
            'purchased_held' => 0,
        ]);
    }
}
