<?php

namespace Tests\Feature\Web\Subscriber;

use App\Enums\SubscriptionsControlActionEnum;
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

class DaysIndicatorTest extends WebAuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setupDaysIndicatorSchema();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::firstOrCreate(['name' => 'subscriber', 'guard_name' => 'web']);
    }

    public function test_days_indicator_hidden_when_more_than_or_equal_15_days_left(): void
    {
        $user = $this->createSubscriberWithPlan(daysLeft: 20, balance: 0);

        $this->actingAs($user)
            ->get('/panel')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('subscriber.days_indicator.visible', false)
                ->where('subscriber.days_indicator.urgent', false)
                ->where('subscriber.days_indicator.days_left', 20));
    }

    public function test_days_indicator_visible_neutral_under_15_days_even_without_funds(): void
    {
        $user = $this->createSubscriberWithPlan(daysLeft: 10, balance: 0);

        $this->actingAs($user)
            ->get('/panel')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('subscriber.days_indicator.visible', true)
                ->where('subscriber.days_indicator.urgent', false)
                ->where('subscriber.days_indicator.days_left', 10)
                ->where('subscriber.days_indicator.shortfall', 1000));
    }

    public function test_days_indicator_includes_shortfall_even_when_days_hidden(): void
    {
        $user = $this->createSubscriberWithPlan(daysLeft: 20, balance: 100);

        $this->actingAs($user)
            ->get('/panel')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('subscriber.days_indicator.visible', false)
                ->where('subscriber.days_indicator.urgent', false)
                ->where('subscriber.days_indicator.shortfall', 900));
    }

    public function test_days_indicator_urgent_when_under_5_days_and_not_enough_funds(): void
    {
        $user = $this->createSubscriberWithPlan(daysLeft: 3, balance: 0);

        $this->actingAs($user)
            ->get('/panel')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('subscriber.days_indicator.visible', true)
                ->where('subscriber.days_indicator.urgent', true)
                ->where('subscriber.days_indicator.days_left', 3)
                ->where('subscriber.days_indicator.shortfall', 1000));
    }

    public function test_days_indicator_not_urgent_when_enough_funds_under_5_days(): void
    {
        $user = $this->createSubscriberWithPlan(daysLeft: 3, balance: 1500);

        $this->actingAs($user)
            ->get('/panel')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('subscriber.days_indicator.visible', true)
                ->where('subscriber.days_indicator.urgent', false)
                ->where('subscriber.days_indicator.days_left', 3)
                ->where('subscriber.days_indicator.shortfall', null));
    }

    public function test_days_indicator_not_urgent_when_stop_scheduled(): void
    {
        $user = $this->createSubscriberWithPlan(daysLeft: 3, balance: 0);

        $subscription = SubscribersSubscriptions::query()
            ->where('subscribers_id', $user->subscriber->id)
            ->first();

        SubscribersSubscriptionsControl::query()->create([
            'subscription_id' => $subscription->id,
            'action' => SubscriptionsControlActionEnum::STOP,
        ]);

        $this->actingAs($user)
            ->get('/panel')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('subscriber.days_indicator.visible', true)
                ->where('subscriber.days_indicator.urgent', false)
                ->where('subscriber.days_indicator.days_left', 3));
    }

    public function test_days_indicator_null_for_trial_plan(): void
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
            ->get('/panel')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('subscriber.days_indicator', null)
                ->where('subscriber.promo_banner.variant', 'trial_active'));
    }

    public function test_days_indicator_last_day_is_urgent_without_funds(): void
    {
        $user = $this->createSubscriberWithPlan(daysLeft: 0, balance: 0);

        $this->actingAs($user)
            ->get('/panel')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('subscriber.days_indicator.visible', true)
                ->where('subscriber.days_indicator.urgent', true)
                ->where('subscriber.days_indicator.days_left', 0));
    }

    private function createSubscriberWithPlan(int $daysLeft, float $balance): User
    {
        $user = $this->createSubscriberUser();

        $endDate = $daysLeft === 0
            ? Carbon::now()->endOfDay()
            : Carbon::now()->startOfDay()->addDays($daysLeft)->setTime(23, 59, 59);

        SubscribersSubscriptions::query()->create([
            'subscribers_id' => $user->subscriber->id,
            'plan_id' => 1,
            'status' => 1,
            'end_date' => $endDate,
            'limits_plan' => [],
            'limits_month' => [],
        ]);

        if ($balance > 0) {
            $this->setUserBalance($user, $balance);
        }

        return $user;
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

    private function setUserBalance(User $user, float $amount): void
    {
        DB::table('balances')->updateOrInsert(
            [
                'payable_id' => $user->id,
                'payable_type' => $user->getMorphClass(),
                'currency' => 'RUB',
            ],
            [
                'value' => $amount,
                'value_pending' => 0,
                'value_on_hold' => 0,
            ]
        );
    }

    private function setupDaysIndicatorSchema(): void
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

        SubscribersPlans::query()->updateOrCreate(
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
