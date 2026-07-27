<?php

namespace Tests\Feature\Web\Subscriber;

use App\Models\Subscribers\Subscribers;
use App\Models\Subscribers\SubscribersPlans;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Web\Auth\WebAuthTestCase;

class ProfileTest extends WebAuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setupProfileSchema();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::firstOrCreate(['name' => 'blog.view', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'subscriber', 'guard_name' => 'web']);
    }

    public function test_admin_without_subscriber_record_can_open_profile(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
        ]);
        $user->givePermissionTo('blog.view');

        $this->actingAs($user)
            ->get('/panel/user/profile')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Profile/Index')
                ->where('subscriptionData', null));
    }

    public function test_subscriber_can_open_profile(): void
    {
        $user = $this->createSubscriberUser();

        $this->actingAs($user)
            ->get('/panel/user/profile')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Subscriber/Profile/Index'));
    }

    public function test_profile_display_limits_collapse_legacy_wb_cabinets(): void
    {
        $user = $this->createSubscriberUser();
        $subscriber = Subscribers::query()->where('user_id', $user->id)->firstOrFail();

        $plan = SubscribersPlans::query()->create([
            'name' => 'Профиль-тариф',
            'description' => 'Test',
            'price' => 2000,
            'duration' => 30,
            'limits_plan' => ['feedbacks_clients' => 3, 'price_calc_clients' => 1],
            'limits_month' => ['ai_text_query' => 50],
            'permissions' => ['subscriber'],
            'status' => 1,
            'hidden' => 0,
        ]);

        SubscribersSubscriptions::query()->create([
            'subscribers_id' => $subscriber->id,
            'plan_id' => $plan->id,
            'status' => 1,
            'limits_plan' => ['feedbacks_clients' => 2, 'price_calc_clients' => 1],
            'limits_month' => ['ai_text_query' => 40],
            'extra_limits_month' => [],
            'end_date' => now()->addDays(14),
        ]);

        $this->actingAs($user)
            ->get('/panel/user/profile')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Profile/Index')
                ->has('subscriptionData.display_limits.plan')
                ->where('subscriptionData.display_limits.plan.0.key', 'wb_cabinets')
                ->where('subscriptionData.display_limits.plan.0.value', 2)
                ->where('subscriptionData.display_limits.plan.0.label', 'Единый кабинет Wildberries')
                ->has('subscriptionData.display_limits.month', 1)
                ->where('subscriptionData.display_limits.month.0.key', 'ai_text_query'));
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

        return $user;
    }

    private function setupProfileSchema(): void
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

        if (! Schema::hasTable('extra_limits')) {
            Schema::create('extra_limits', function (Blueprint $table) {
                $table->id();
                $table->string('slug')->unique();
                $table->string('name');
                $table->decimal('price', 12, 4)->default(0);
                $table->unsignedInteger('order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('subscribers_subscriptions')) {
            Schema::create('subscribers_subscriptions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('subscribers_id')->index();
                $table->unsignedBigInteger('plan_id')->nullable();
                $table->unsignedTinyInteger('status')->default(1);
                $table->timestamp('end_date')->nullable();
                $table->json('limits_plan')->nullable();
                $table->json('limits_month')->nullable();
                $table->json('extra_limits_month')->nullable();
                $table->timestamps();
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
    }
}