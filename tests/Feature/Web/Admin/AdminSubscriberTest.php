<?php

namespace Tests\Feature\Web\Admin;

use App\Models\Subscribers\Subscribers;
use App\Models\Subscribers\SubscribersPlans;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Web\Auth\WebAuthTestCase;

class AdminSubscriberTest extends WebAuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setupAdminSchema();

        Role::firstOrCreate([
            'name' => 'super-admin',
            'guard_name' => 'web',
        ]);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function test_subscribers_page_requires_super_admin(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('password'),
        ]);

        $this->actingAs($user)
            ->get('/cw-page/subscribers')
            ->assertForbidden();
    }

    public function test_super_admin_can_open_subscribers_page(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('password'),
        ]);

        $user->assignRole('super-admin');

        $this->actingAs($user)
            ->get('/cw-page/subscribers')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Subscribers/Index'));
    }

    public function test_super_admin_can_change_subscriber_plan_without_invalid_end_date(): void
    {
        SubscribersPlans::query()->updateOrCreate(
            ['id' => 1],
            [
                'name' => 'Базовый',
                'description' => 'Basic plan',
                'price' => 1000,
                'duration' => 30,
                'limits_plan' => [],
                'limits_month' => [],
                'permissions' => ['subscriber'],
                'status' => 1,
                'hidden' => 0,
            ]
        );

        SubscribersPlans::query()->updateOrCreate(
            ['id' => 2],
            [
                'name' => 'Стандарт',
                'description' => 'Standard plan',
                'price' => 2000,
                'duration' => 30,
                'limits_plan' => [],
                'limits_month' => [],
                'permissions' => ['subscriber'],
                'status' => 1,
                'hidden' => 0,
            ]
        );

        $subscriberUser = User::factory()->create([
            'password' => Hash::make('password'),
        ]);

        $subscriber = Subscribers::query()->create([
            'user_id' => $subscriberUser->id,
            'status' => 1,
        ]);

        SubscribersSubscriptions::query()->create([
            'subscribers_id' => $subscriber->id,
            'plan_id' => 1,
            'status' => 1,
            'end_date' => Carbon::now()->addDays(20),
            'limits_plan' => [],
            'limits_month' => [],
        ]);

        $admin = User::factory()->create([
            'password' => Hash::make('password'),
        ]);
        $admin->assignRole('super-admin');

        $this->actingAs($admin)
            ->put("/cw-page/subscribers/{$subscriber->id}", [
                'user_id' => $subscriberUser->id,
                'plan_id' => 2,
                'user' => [
                    'name' => $subscriberUser->name,
                    'email' => $subscriberUser->email,
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Подписчик обновлён');

        $subscription = SubscribersSubscriptions::query()
            ->where('subscribers_id', $subscriber->id)
            ->where('status', 1)
            ->first();

        $this->assertNotNull($subscription);
        $this->assertSame(2, (int) $subscription->plan_id);

        $parsedEndDate = Carbon::parse($subscription->getRawOriginal('end_date'));
        $this->assertGreaterThanOrEqual(Carbon::now()->year, $parsedEndDate->year);
    }

    public function test_super_admin_can_update_subscriber_limits(): void
    {
        SubscribersPlans::query()->updateOrCreate(
            ['id' => 1],
            [
                'name' => 'Базовый',
                'description' => 'Basic plan',
                'price' => 1000,
                'duration' => 30,
                'limits_plan' => ['feedbacks_clients' => 3],
                'limits_month' => ['ai_text_query' => 100],
                'permissions' => ['subscriber'],
                'status' => 1,
                'hidden' => 0,
            ]
        );

        $subscriberUser = User::factory()->create([
            'password' => Hash::make('password'),
        ]);

        $subscriber = Subscribers::query()->create([
            'user_id' => $subscriberUser->id,
            'status' => 1,
        ]);

        $subscription = SubscribersSubscriptions::query()->create([
            'subscribers_id' => $subscriber->id,
            'plan_id' => 1,
            'status' => 1,
            'end_date' => Carbon::now()->addDays(10),
            'limits_plan' => ['feedbacks_clients' => 2],
            'limits_month' => ['ai_text_query' => 50],
            'extra_limits_month' => ['ai_text_query' => 5],
        ]);

        $admin = User::factory()->create([
            'password' => Hash::make('password'),
        ]);
        $admin->assignRole('super-admin');

        $this->actingAs($admin)
            ->put("/cw-page/subscribers/{$subscriber->id}", [
                'user_id' => $subscriberUser->id,
                'user' => [
                    'name' => $subscriberUser->name,
                    'email' => $subscriberUser->email,
                ],
                'subscriptions' => [
                    [
                        'id' => $subscription->id,
                        'limits_plan' => [
                            'feedbacks_clients' => 4,
                            'repricer_nmid' => -3,
                        ],
                        'limits_month' => [
                            'ai_text_query' => 75,
                        ],
                        'extra_limits_month' => [
                            'ai_text_query' => 10,
                            'ai_image_query' => 2,
                        ],
                    ],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Подписчик обновлён');

        $subscription->refresh();

        $this->assertSame(['feedbacks_clients' => 4, 'repricer_nmid' => 0], $subscription->limits_plan);
        $this->assertSame(['ai_text_query' => 75], $subscription->limits_month);
        $this->assertSame(['ai_text_query' => 10, 'ai_image_query' => 2], $subscription->extra_limits_month);
    }

    public function test_super_admin_edit_page_includes_limit_keys(): void
    {
        $subscriberUser = User::factory()->create([
            'password' => Hash::make('password'),
        ]);

        $subscriber = Subscribers::query()->create([
            'user_id' => $subscriberUser->id,
            'status' => 1,
        ]);

        $admin = User::factory()->create([
            'password' => Hash::make('password'),
        ]);
        $admin->assignRole('super-admin');

        $this->actingAs($admin)
            ->get("/cw-page/subscribers/{$subscriber->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Subscribers/Edit')
                ->has('limitKeys')
                ->where('limitKeys.0', 'adverts_clients'));
    }

    public function test_super_admin_can_open_plans_and_payments_pages(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('password'),
        ]);

        $user->assignRole('super-admin');

        $this->actingAs($user)
            ->get('/cw-page/plans')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Plans/Index'));

        $this->actingAs($user)
            ->get('/cw-page/payments')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Payments/Index'));
    }

    private function setupAdminSchema(): void
    {
        if (! Schema::hasTable('subscribers_plans')) {
            Schema::create('subscribers_plans', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->decimal('price', 10, 2)->default(0);
                $table->unsignedInteger('duration')->default(30);
                $table->text('description')->nullable();
                $table->json('limits_plan')->nullable();
                $table->json('limits_month')->nullable();
                $table->json('permissions')->nullable();
                $table->unsignedTinyInteger('status')->default(1);
                $table->unsignedTinyInteger('hidden')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('subscribers_subscriptions')) {
            Schema::create('subscribers_subscriptions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('subscribers_id');
                $table->unsignedBigInteger('plan_id');
                $table->json('limits_plan')->nullable();
                $table->json('extra_limits_plan')->nullable();
                $table->json('limits_month')->nullable();
                $table->json('extra_limits_month')->nullable();
                $table->timestamp('start_date')->nullable();
                $table->timestamp('end_date')->nullable();
                $table->unsignedTinyInteger('status')->default(1);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('extra_limits')) {
            Schema::create('extra_limits', function (Blueprint $table) {
                $table->id();
                $table->string('limit_name');
                $table->unsignedInteger('quantity')->default(0);
                $table->decimal('price', 10, 2)->default(0);
                $table->unsignedInteger('order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('payments_transactions')) {
            Schema::create('payments_transactions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->decimal('amount', 12, 2)->default(0);
                $table->string('description')->nullable();
                $table->string('system')->nullable();
                $table->string('system_id')->nullable();
                $table->string('status')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_costs')) {
            Schema::create('ai_costs', function (Blueprint $table) {
                $table->id();
                $table->string('provider')->nullable();
                $table->decimal('cost', 12, 6)->default(0);
                $table->date('date')->nullable();
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

        if (! Schema::hasTable('transactions')) {
            Schema::create('transactions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('from_type')->nullable();
                $table->unsignedBigInteger('from_id')->nullable();
                $table->string('to_type')->nullable();
                $table->unsignedBigInteger('to_id')->nullable();
                $table->decimal('amount', 16, 8)->default(0);
                $table->decimal('received', 16, 8)->default(0);
                $table->string('currency', 10)->default('RUB');
                $table->string('status')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();
            });
        }
    }
}