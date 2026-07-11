<?php

namespace Tests\Feature\Web\Subscriber;

use App\Models\PaymentsTransaction;
use App\Services\PaymentService;
use App\Models\Subscribers\Subscribers;
use App\Models\Subscribers\SubscribersPlans;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\Subscribers\SubscribersSubscriptionsControl;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Web\Auth\WebAuthTestCase;

class PlansTest extends WebAuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setupPlansSchema();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::firstOrCreate(['name' => 'subscriber', 'guard_name' => 'web']);
    }

    public function test_subscriber_can_open_plans_page_with_visible_plans_only(): void
    {
        SubscribersPlans::query()->updateOrCreate(
            ['id' => 10],
            [
                'name' => 'Скрытый',
                'description' => 'Hidden plan',
                'price' => 500,
                'duration' => 30,
                'limits_plan' => [],
                'limits_month' => [],
                'permissions' => ['subscriber'],
                'status' => 1,
                'hidden' => 1,
            ]
        );

        SubscribersPlans::query()->updateOrCreate(
            ['id' => 11],
            [
                'name' => 'Стандарт',
                'description' => 'Visible plan',
                'price' => 1500,
                'duration' => 30,
                'limits_plan' => ['feedbacks_clients' => 3],
                'limits_month' => [],
                'permissions' => ['subscriber'],
                'status' => 1,
                'hidden' => 0,
            ]
        );

        $user = $this->createSubscriberUser();

        $this->actingAs($user)
            ->get('/panel/plans')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Plans/Index')
                ->has('plans', 2)
                ->where('plans.0.name', 'Базовый')
                ->where('plans.1.name', 'Стандарт')
                ->missing('plans.2'));
    }

    public function test_trial_user_gets_promo_banner_in_shared_props(): void
    {
        $user = $this->createSubscriberUser();

        SubscribersPlans::query()->updateOrCreate(
            ['id' => 2],
            [
                'name' => 'Тестовый',
                'description' => 'Trial',
                'price' => 0,
                'duration' => 7,
                'limits_plan' => [],
                'limits_month' => [],
                'permissions' => ['subscriber'],
                'status' => 1,
                'hidden' => 1,
            ]
        );

        SubscribersSubscriptions::query()->create([
            'subscribers_id' => $user->subscriber->id,
            'plan_id' => 2,
            'status' => 1,
            'end_date' => Carbon::now()->addDays(3),
            'limits_plan' => [],
            'limits_month' => [],
        ]);

        $this->actingAs($user)
            ->get('/panel/plans')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('subscriber.promo_banner.variant', 'trial_active')
                ->where('subscriber.promo_banner.cta_href', '/panel/plans'));
    }

    public function test_expired_paid_subscription_gets_subscription_expired_banner(): void
    {
        $user = $this->createSubscriberUser();

        SubscribersSubscriptions::query()->create([
            'subscribers_id' => $user->subscriber->id,
            'plan_id' => 1,
            'status' => 0,
            'end_date' => Carbon::now()->subDay(),
            'limits_plan' => [],
            'limits_month' => [],
        ]);

        $this->actingAs($user)
            ->get('/panel/plans')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('subscriber.promo_banner.variant', 'subscription_expired')
                ->where('subscriber.promo_banner.cta_label', 'Продлить тариф'));
    }

    public function test_subscriber_can_schedule_downgrade_with_success_details(): void
    {
        DB::table('subscribers_plans')->updateOrInsert(
            ['id' => 11],
            [
                'name' => 'Стандарт',
                'description' => 'Higher plan',
                'price' => 1500,
                'duration' => 30,
                'limits_plan' => json_encode(['feedbacks_clients' => 3], JSON_UNESCAPED_UNICODE),
                'limits_month' => json_encode([], JSON_UNESCAPED_UNICODE),
                'permissions' => json_encode(['subscriber'], JSON_UNESCAPED_UNICODE),
                'status' => 1,
                'hidden' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $user = $this->createSubscriberUser();

        SubscribersSubscriptions::query()
            ->where('subscribers_id', $user->subscriber->id)
            ->delete();

        SubscribersSubscriptions::query()->create([
            'subscribers_id' => $user->subscriber->id,
            'plan_id' => 11,
            'status' => 1,
            'end_date' => Carbon::now()->addDays(10),
            'limits_plan' => ['feedbacks_clients' => 2],
            'limits_month' => [],
        ]);

        $this->assertDatabaseHas('subscribers_subscriptions', [
            'subscribers_id' => $user->subscriber->id,
            'plan_id' => 11,
        ]);

        $response = $this->actingAs($user)
            ->from('/panel/plans')
            ->post('/panel/user/change-plan', ['plan_id' => 1]);

        $response->assertRedirect('/panel/plans')
            ->assertSessionHas('success', 'Запланирован переход на тариф «Базовый»')
            ->assertSessionHas('success_details.type', 'downgrade_scheduled')
            ->assertSessionHas('success_details.pending_plan_name', 'Базовый');

        $this->assertDatabaseHas('subscribers_subscriptions_control', [
            'subscription_id' => SubscribersSubscriptions::query()->where('subscribers_id', $user->subscriber->id)->value('id'),
            'action' => 'LOWER',
        ]);

        $this->actingAs($user)
            ->get('/panel/plans')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('pendingDowngrade.plan_name', 'Базовый')
                ->where('plans.0.is_pending_downgrade', true));
    }

    public function test_subscriber_can_cancel_scheduled_downgrade(): void
    {
        DB::table('subscribers_plans')->updateOrInsert(
            ['id' => 11],
            [
                'name' => 'Стандарт',
                'description' => 'Higher plan',
                'price' => 1500,
                'duration' => 30,
                'limits_plan' => json_encode(['feedbacks_clients' => 3], JSON_UNESCAPED_UNICODE),
                'limits_month' => json_encode([], JSON_UNESCAPED_UNICODE),
                'permissions' => json_encode(['subscriber'], JSON_UNESCAPED_UNICODE),
                'status' => 1,
                'hidden' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $user = $this->createSubscriberUser();

        $subscription = SubscribersSubscriptions::query()->create([
            'subscribers_id' => $user->subscriber->id,
            'plan_id' => 11,
            'status' => 1,
            'end_date' => Carbon::now()->addDays(10),
            'limits_plan' => ['feedbacks_clients' => 2],
            'limits_month' => [],
        ]);

        SubscribersSubscriptionsControl::query()->create([
            'subscription_id' => $subscription->id,
            'action' => 'LOWER',
            'config' => ['plan_id' => 1],
        ]);

        $this->actingAs($user)
            ->from('/panel/plans')
            ->post('/panel/user/cancel-downgrade')
            ->assertRedirect('/panel/plans')
            ->assertSessionHas('success', 'Переход на более низкий тариф отменён');

        $this->assertDatabaseMissing('subscribers_subscriptions_control', [
            'subscription_id' => $subscription->id,
            'action' => 'LOWER',
        ]);

        $this->actingAs($user)
            ->get('/panel/plans')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('pendingDowngrade', null)
                ->where('plans.0.is_pending_downgrade', false));
    }

    public function test_deposit_with_plan_id_stores_plan_on_transaction(): void
    {
        $user = $this->createSubscriberUser();

        $paymentService = \Mockery::mock(PaymentService::class);
        $paymentService->shouldReceive('createPayment')
            ->once()
            ->andReturn('https://yookassa.test/pay');
        $this->instance(PaymentService::class, $paymentService);

        $this->actingAs($user)
            ->post('/panel/payments/deposit', [
                'amount' => 500,
                'plan_id' => 1,
            ]);

        $transaction = PaymentsTransaction::query()->where('user_id', $user->id)->latest('id')->first();

        $this->assertNotNull($transaction);
        $this->assertSame(1, (int) $transaction->plan_id);
        $this->assertSame(500.0, (float) $transaction->amount);
        $this->assertStringContainsString('тарифа', (string) $transaction->description);
    }

    private function createSubscriberUser(): User
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
        ]);

        $user->assignRole('Подписчик');
        $user->givePermissionTo('subscriber');

        Subscribers::query()->create([
            'user_id' => $user->id,
            'status' => 1,
        ]);

        $user->load('subscriber');

        return $user;
    }

    private function setupPlansSchema(): void
    {
        if (! Schema::hasTable('subscribers_plans')) {
            Schema::create('subscribers_plans', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->text('description')->nullable();
                $table->decimal('price', 10, 2)->default(0);
                $table->unsignedInteger('duration')->default(30);
                $table->json('limits_plan')->nullable();
                $table->json('limits_month')->nullable();
                $table->json('permissions')->nullable();
                $table->unsignedTinyInteger('status')->default(1);
                $table->unsignedTinyInteger('hidden')->default(0);
                $table->timestamps();
            });
        }

        SubscribersPlans::query()->firstOrCreate(
            ['id' => 1],
            [
                'name' => 'Базовый',
                'description' => 'Test plan',
                'price' => 1000,
                'duration' => 30,
                'limits_plan' => [],
                'limits_month' => [],
                'permissions' => ['subscriber'],
                'status' => 1,
                'hidden' => 0,
            ]
        );

        if (! Schema::hasTable('subscribers_subscriptions')) {
            Schema::create('subscribers_subscriptions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('subscribers_id')->index();
                $table->unsignedBigInteger('plan_id')->nullable();
                $table->unsignedTinyInteger('status')->default(1);
                $table->timestamp('start_date')->nullable();
                $table->timestamp('end_date')->nullable();
                $table->json('limits_plan')->nullable();
                $table->json('limits_month')->nullable();
                $table->json('extra_limits_month')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('payments_transactions')) {
            Schema::create('payments_transactions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->decimal('amount', 10, 2)->default(0);
                $table->unsignedBigInteger('plan_id')->nullable();
                $table->string('description')->nullable();
                $table->string('system')->nullable();
                $table->string('system_id')->nullable();
                $table->unsignedTinyInteger('status')->default(0);
                $table->timestamps();
            });
        } elseif (! Schema::hasColumn('payments_transactions', 'plan_id')) {
            Schema::table('payments_transactions', function (Blueprint $table) {
                $table->unsignedBigInteger('plan_id')->nullable()->after('amount');
            });
        }

        if (! Schema::hasTable('balances')) {
            Schema::create('balances', function (Blueprint $table) {
                $table->id();
                $table->morphs('payable');
                $table->decimal('value', 16, 8)->default(0);
                $table->decimal('value_pending', 16, 8)->default(0);
                $table->decimal('value_on_hold', 16, 8)->default(0);
                $table->string('currency', 10)->index();
                $table->unique(['payable_id', 'payable_type', 'currency'], 'unique_balance');
            });
        }

        if (! Schema::hasTable('subscribers_subscriptions_control')) {
            Schema::create('subscribers_subscriptions_control', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('subscription_id')->index();
                $table->string('action');
                $table->json('config')->nullable();
                $table->timestamps();
            });
        }
    }
}