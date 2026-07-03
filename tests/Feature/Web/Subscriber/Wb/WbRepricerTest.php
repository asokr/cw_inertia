<?php

namespace Tests\Feature\Web\Subscriber\Wb;

use App\Models\Subscribers\Subscribers;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\Subscribers\Wb\Repricer\RepricerCabinets;
use App\Models\Subscribers\Wb\Repricer\RepricerSettings;
use App\Models\Subscribers\Wb\Repricer\RepricerStocks;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Web\Auth\WebAuthTestCase;

class WbRepricerTest extends WebAuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setupRepricerSchema();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::firstOrCreate([
            'name' => 'subscriber wb repricer',
            'guard_name' => 'web',
        ]);
    }

    public function test_guest_cannot_access_repricer_index(): void
    {
        $this->get('/panel/wb/repricer')->assertUnauthorized();
    }

    public function test_user_without_permission_cannot_access_index(): void
    {
        $user = $this->createSubscriberUser();

        $this->actingAs($user)
            ->get('/panel/wb/repricer')
            ->assertForbidden();
    }

    public function test_subscriber_with_permission_can_access_index(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);

        $this->actingAs($user)
            ->get('/panel/wb/repricer')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Wb/Repricer/Index')
                ->has('cabinets'));
    }

    public function test_index_lists_owned_cabinets(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'Test Cabinet');

        $this->actingAs($user)
            ->get('/panel/wb/repricer')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('cabinets.0.name', 'Test Cabinet')
                ->where('cabinets.0.id', $cabinet->id));
    }

    public function test_strategy_hub_renders_for_owner(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'Hub Cabinet');

        $this->actingAs($user)
            ->get("/panel/wb/repricer/cabinets/{$cabinet->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Wb/Repricer/Cabinet/Show')
                ->where('cabinet.id', $cabinet->id)
                ->has('strategies', 2));
    }

    public function test_time_index_renders_for_owner(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'Time Cabinet');
        $this->createTimeSetting($cabinet, 123456);

        $this->actingAs($user)
            ->get("/panel/wb/repricer/cabinets/{$cabinet->id}/time")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Wb/Repricer/Cabinet/Time/Index')
                ->where('cabinet.id', $cabinet->id)
                ->has('settings')
                ->where('settings.0.nmID', 123456));
    }

    public function test_stocks_index_renders_for_owner(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'Stocks Cabinet');
        $this->createStock($cabinet, 654321);

        $this->actingAs($user)
            ->get("/panel/wb/repricer/cabinets/{$cabinet->id}/stocks")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Wb/Repricer/Cabinet/Stocks/Index')
                ->where('cabinet.id', $cabinet->id)
                ->has('stocks')
                ->where('stocks.0.nmID', 654321));
    }

    public function test_cabinet_show_forbidden_for_foreign_cabinet(): void
    {
        $owner = $this->createSubscriberUser(withPermission: true);
        $intruder = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($owner, 'Foreign');

        $this->actingAs($intruder)
            ->get("/panel/wb/repricer/cabinets/{$cabinet->id}")
            ->assertForbidden();
    }

    public function test_destroy_cabinet_redirects_with_success(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'Delete Me');

        $this->actingAs($user)
            ->delete("/panel/wb/repricer/cabinets/{$cabinet->id}")
            ->assertRedirect('/panel/wb/repricer')
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('wb_repricer_cabinets', ['id' => $cabinet->id]);
    }

    private function createSubscriberUser(bool $withPermission = false): User
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
        ]);

        $user->assignRole('Подписчик');

        if ($withPermission) {
            $user->givePermissionTo('subscriber wb repricer');
        }

        $subscriber = Subscribers::query()->create([
            'user_id' => $user->id,
            'status' => 1,
        ]);

        SubscribersSubscriptions::query()->create([
            'subscribers_id' => $subscriber->id,
            'plan_id' => 1,
            'status' => 1,
            'end_date' => now()->addMonth(),
            'limits_plan' => ['repricer_nmid' => 10],
        ]);

        return $user;
    }

    private function createCabinet(User $user, string $name): RepricerCabinets
    {
        return RepricerCabinets::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'apikey' => 'test-api-key',
        ]);
    }

    private function createTimeSetting(RepricerCabinets $cabinet, int $nmId): RepricerSettings
    {
        return RepricerSettings::query()->create([
            'cabinet_id' => $cabinet->id,
            'nmID' => $nmId,
            'name' => 'Test',
            'price_type' => 'PRICE',
            'strategy' => 'TIME',
            'pricing_modifier_type' => 'FIXED',
            'terms' => [['start' => '09:00', 'end' => '18:00', 'value' => 100]],
            'status' => true,
            'active' => false,
        ]);
    }

    private function createStock(RepricerCabinets $cabinet, int $nmId): RepricerStocks
    {
        return RepricerStocks::query()->create([
            'cabinet_id' => $cabinet->id,
            'nmID' => $nmId,
            'name' => 'Test stock',
            'strategy' => 1,
            'terms' => ['qty' => 5, 'data' => [['from' => 10, 'add_to_price' => 50, 'is_procent' => false]]],
            'status' => true,
            'active' => false,
        ]);
    }

    private function setupRepricerSchema(): void
    {
        if (! Schema::hasTable('wb_repricer_cabinets')) {
            Schema::create('wb_repricer_cabinets', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('name');
                $table->text('apikey')->nullable();
                $table->integer('error_code')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('wb_repricer_settings')) {
            Schema::create('wb_repricer_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('cabinet_id')->index();
                $table->string('name')->nullable();
                $table->unsignedBigInteger('nmID')->index();
                $table->decimal('base_value', 12, 2)->nullable();
                $table->decimal('base_discount', 8, 2)->nullable();
                $table->string('price_type')->default('PRICE');
                $table->string('strategy')->default('TIME');
                $table->string('pricing_modifier_type')->default('FIXED');
                $table->json('terms')->nullable();
                $table->boolean('active')->default(false);
                $table->boolean('status')->default(false);
                $table->unsignedInteger('repeats_counter')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('wb_repricer_stocks')) {
            Schema::create('wb_repricer_stocks', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('cabinet_id')->index();
                $table->string('name')->nullable();
                $table->unsignedBigInteger('nmID')->index();
                $table->decimal('base_value', 12, 2)->nullable();
                $table->decimal('base_discount', 8, 2)->nullable();
                $table->unsignedTinyInteger('strategy')->default(1);
                $table->boolean('editable_size_price')->default(false);
                $table->json('terms')->nullable();
                $table->decimal('added_value', 12, 2)->nullable();
                $table->boolean('active')->default(false);
                $table->boolean('status')->default(false);
                $table->unsignedInteger('repeats_counter')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('wb_repricer_logs')) {
            Schema::create('wb_repricer_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('cabinet_id')->index();
                $table->unsignedBigInteger('nmID')->index();
                $table->text('message')->nullable();
                $table->string('type')->nullable();
                $table->string('strategy')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('subscribers_plans')) {
            Schema::create('subscribers_plans', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->decimal('price', 10, 2)->default(0);
                $table->unsignedInteger('duration')->default(30);
                $table->json('limits_plan')->nullable();
                $table->unsignedTinyInteger('status')->default(1);
                $table->timestamps();
            });

            DB::table('subscribers_plans')->insert([
                'id' => 1,
                'name' => 'Test Plan',
                'price' => 0,
                'duration' => 30,
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
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