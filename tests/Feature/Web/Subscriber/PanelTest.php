<?php

namespace Tests\Feature\Web\Subscriber;

use App\Models\PaymentsTransaction;
use App\Models\Subscribers\Subscribers;
use App\Models\Subscribers\SubscribersPlans;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\Subscribers\Wb\Feedbacks\FeedbacksClients;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Web\Auth\WebAuthTestCase;

class PanelTest extends WebAuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setupPanelSchema();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::firstOrCreate([
            'name' => 'subscriber',
            'guard_name' => 'web',
        ]);
    }

    public function test_guest_cannot_access_panel(): void
    {
        $this->get('/panel')->assertRedirect('/login');
    }

    public function test_subscriber_can_access_panel_dashboard(): void
    {
        $user = $this->createSubscriberUser();

        $this->actingAs($user)
            ->get('/panel')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Panel/Index')
                ->has('dashboard')
                ->has('dashboard.stats')
                ->has('dashboard.recent_payments')
                ->where('dashboard.stats.cabinets_total', 0)
                ->where('dashboard.stats.active_bots', 0));
    }

    public function test_panel_dashboard_reflects_subscription_cabinets_limits_and_payments(): void
    {
        $user = $this->createSubscriberUser();
        $subscriber = Subscribers::query()->where('user_id', $user->id)->first();

        $plan = SubscribersPlans::query()->create([
            'name' => 'Бизнес',
            'description' => 'Test plan',
            'duration' => 30,
            'price' => 5000,
            'status' => 1,
            'hidden' => 0,
            'limits_plan' => json_encode(['feedbacks_clients' => 2], JSON_UNESCAPED_UNICODE),
            'limits_month' => json_encode(['ai_text_query' => 10], JSON_UNESCAPED_UNICODE),
        ]);

        SubscribersSubscriptions::query()->create([
            'subscribers_id' => $subscriber->id,
            'plan_id' => $plan->id,
            'status' => 1,
            'limits_plan' => ['feedbacks_clients' => 1],
            'limits_month' => ['ai_text_query' => 7],
            'extra_limits_month' => ['ai_text_query' => 3],
            'end_date' => now()->addDays(10),
        ]);

        FeedbacksClients::query()->create([
            'subscriber_id' => $subscriber->id,
            'name' => 'WB Cabinet',
            'brands' => '',
            'apikey' => 'test-key',
            'bot_status' => 1,
        ]);

        PaymentsTransaction::query()->create([
            'user_id' => $user->id,
            'amount' => 1500,
            'description' => 'Пополнение баланса',
            'status' => 'CONFIRMED',
            'system' => 'YooKassa',
        ]);

        $this->actingAs($user)
            ->get('/panel')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Panel/Index')
                ->where('dashboard.subscription.plan_name', 'Бизнес')
                ->where('dashboard.subscription.status', 1)
                ->where('dashboard.subscription.remaining_limits.ai_text_query', 10)
                ->where('dashboard.subscription.remaining_limits.feedbacks_clients', 1)
                ->where('dashboard.stats.cabinets_total', 1)
                ->where('dashboard.stats.active_bots', 1)
                ->where('dashboard.stats.cabinets_by_tool.wb_feedbacks', 1)
                ->has('dashboard.recent_payments', 1)
                ->where('dashboard.recent_payments.0.amount', 1500)
                ->where('dashboard.recent_payments.0.description', 'Пополнение баланса'));
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

    private function setupPanelSchema(): void
    {
        if (! Schema::hasTable('subscribers_plans')) {
            Schema::create('subscribers_plans', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->text('description')->nullable();
                $table->decimal('price', 10, 2)->default(0);
                $table->unsignedInteger('duration')->default(30);
                $table->unsignedTinyInteger('status')->default(1);
                $table->unsignedTinyInteger('hidden')->default(0);
                $table->json('limits_plan')->nullable();
                $table->json('limits_month')->nullable();
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

        if (! Schema::hasTable('payments_transactions')) {
            Schema::create('payments_transactions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->decimal('amount', 12, 2)->default(0);
                $table->string('description')->nullable();
                $table->string('status')->nullable();
                $table->string('system')->nullable();
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

        $cabinetTables = [
            'subs_wb_feedbacks_clients' => function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('subscriber_id')->index();
                $table->string('name');
                $table->string('brands')->nullable();
                $table->text('apikey')->nullable();
                $table->unsignedTinyInteger('bot_status')->default(0);
                $table->timestamps();
            },
            'oz_feedbacks_clients' => function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->unsignedTinyInteger('bot_status')->default(0);
            },
            'wb_profitability_cabinets' => function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
            },
            'wb_price_cabinets' => function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
            },
            'wb_repricer_cabinets' => function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
            },
            'wb_ai_cabinet_analyzer_cabinets' => function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
            },
            'oz_price_calc_cabinets' => function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
            },
        ];

        foreach ($cabinetTables as $tableName => $callback) {
            if (! Schema::hasTable($tableName)) {
                Schema::create($tableName, $callback);
            }
        }
    }
}