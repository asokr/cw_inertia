<?php

namespace Tests\Feature\Web\Subscriber;

use App\Models\ExtraLimits;
use App\Models\Subscribers\Subscribers;
use App\Models\Subscribers\SubscribersPlans;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Web\Auth\WebAuthTestCase;

class ExtraLimitPurchaseTest extends WebAuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setupSchema();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::firstOrCreate(['name' => 'subscriber', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
    }

    public function test_purchase_adds_quantity_and_charges_unit_price_times_qty(): void
    {
        $user = $this->createSubscriberWithSubscription();
        deposit(500, 'RUB')->to($user)->overcharge()->commit();

        $limit = ExtraLimits::query()->create([
            'slug' => 'ai_text_query',
            'name' => 'Текстовые запросы к ИИ',
            'price' => 2.5,
            'order' => 1,
        ]);

        $this->actingAs($user)
            ->post('/panel/user/extra-limits', [
                'id' => $limit->id,
                'quantity' => 100,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $subscription = $user->getSubscriptions();
        $this->assertNotNull($subscription);
        $subscription->refresh();
        $this->assertSame(100, (int) ($subscription->extra_limits_month['ai_text_query'] ?? 0));

        // 500 - (100 * 2.5) = 250
        $this->assertEqualsWithDelta(250.0, (float) (string) $user->balance('RUB')->value, 0.01);
    }

    public function test_purchase_fails_without_enough_funds(): void
    {
        $user = $this->createSubscriberWithSubscription();

        $limit = ExtraLimits::query()->create([
            'slug' => 'ai_image_query',
            'name' => 'Генерация изображений ИИ',
            'price' => 10,
            'order' => 1,
        ]);

        $this->actingAs($user)
            ->post('/panel/user/extra-limits', [
                'id' => $limit->id,
                'quantity' => 50,
            ])
            ->assertRedirect()
            ->assertSessionHas('error', 'Недостаточно средств');
    }

    public function test_purchase_requires_valid_quantity(): void
    {
        $user = $this->createSubscriberWithSubscription();
        deposit(1000, 'RUB')->to($user)->overcharge()->commit();

        $limit = ExtraLimits::query()->create([
            'slug' => 'ai_video_query',
            'name' => 'Генерация видео ИИ',
            'price' => 1,
            'order' => 1,
        ]);

        $this->actingAs($user)
            ->from('/panel/user/profile')
            ->post('/panel/user/extra-limits', [
                'id' => $limit->id,
                'quantity' => 0,
            ])
            ->assertRedirect('/panel/user/profile')
            ->assertSessionHasErrors('quantity');
    }

    public function test_purchase_fails_without_subscription(): void
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

        deposit(1000, 'RUB')->to($user)->overcharge()->commit();

        $limit = ExtraLimits::query()->create([
            'slug' => 'feedbacks_gpt_query',
            'name' => 'Запросы к ИИ для отзывов',
            'price' => 1,
            'order' => 1,
        ]);

        $this->actingAs($user)
            ->post('/panel/user/extra-limits', [
                'id' => $limit->id,
                'quantity' => 10,
            ])
            ->assertRedirect()
            ->assertSessionHas('error', 'У вас нет активной подписки');
    }

    public function test_admin_can_create_unit_priced_limit(): void
    {
        $admin = User::factory()->create([
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
        ]);
        $admin->assignRole('super-admin');

        $this->actingAs($admin)
            ->post('/cw-page/extra-limits', [
                'slug' => 'custom_limit',
                'name' => 'Кастомный лимит',
                'price' => 3.5,
                'order' => 10,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('extra_limits', [
            'slug' => 'custom_limit',
            'name' => 'Кастомный лимит',
        ]);

        $row = ExtraLimits::query()->where('slug', 'custom_limit')->first();
        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(3.5, (float) $row->price, 0.001);
    }

    private function createSubscriberWithSubscription(): User
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
        ]);

        $user->assignRole('Подписчик');
        $user->givePermissionTo('subscriber');

        $subscriber = Subscribers::query()->create([
            'user_id' => $user->id,
            'status' => 1,
        ]);

        SubscribersSubscriptions::query()->create([
            'subscribers_id' => $subscriber->id,
            'plan_id' => 1,
            'status' => 1,
            'limits_plan' => [],
            'limits_month' => [],
            'extra_limits_month' => [],
            'end_date' => now()->addDays(30),
        ]);

        $user->load('subscriber');

        return $user;
    }

    private function setupSchema(): void
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
                $table->id();
                $table->uuid('uuid')->unique();
                $table->nullableMorphs('from');
                $table->nullableMorphs('to');
                $table->decimal('amount', 64, 0)->default(0);
                $table->decimal('commission', 64, 0)->default(0);
                $table->decimal('received', 64, 0)->default(0);
                $table->string('currency', 10)->index();
                $table->string('status')->nullable();
                $table->string('processor_id')->nullable();
                $table->json('meta')->nullable();
                $table->boolean('archived')->default(false);
                $table->boolean('invisible')->default(false);
                $table->unsignedBigInteger('batch')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }
    }
}
