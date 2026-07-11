<?php

namespace Tests\Feature\Web\Subscriber\Wb;

use App\Models\Subscribers\Subscribers;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\Subscribers\Wb\PriceCalculation\PriceCalculationCabinets;
use App\Models\Subscribers\Wb\PriceCalculation\PriceCalculationV2Settings;
use App\Models\User;
use App\Services\Wb\WbPriceCalculationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Web\Auth\WebAuthTestCase;

class WbPriceCalcTest extends WebAuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setupPriceCalcSchema();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::firstOrCreate([
            'name' => 'subscriber wb price calculator',
            'guard_name' => 'web',
        ]);
    }

    public function test_guest_cannot_access_wb_price_calc_index(): void
    {
        $this->get('/panel/wb/price-calc')->assertUnauthorized();
    }

    public function test_user_without_permission_cannot_access_index(): void
    {
        $user = $this->createSubscriberUser();

        $this->actingAs($user)
            ->get('/panel/wb/price-calc')
            ->assertForbidden();
    }

    public function test_subscriber_with_permission_can_access_index(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);

        $this->actingAs($user)
            ->get('/panel/wb/price-calc')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Wb/PriceCalc/Index')
                ->has('cabinets'));
    }

    public function test_index_lists_owned_cabinets(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'Test Cabinet');

        $this->actingAs($user)
            ->get('/panel/wb/price-calc')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('cabinets.0.name', 'Test Cabinet')
                ->where('cabinets.0.id', $cabinet->id));
    }

    public function test_cabinet_show_renders_for_owner(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'Workspace Cabinet');

        $this->actingAs($user)
            ->get("/panel/wb/price-calc/cabinets/{$cabinet->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Wb/PriceCalc/Cabinet/Show')
                ->where('cabinet.id', $cabinet->id)
                ->has('cards')
                ->has('settings'));
    }

    public function test_cabinet_show_forbidden_for_foreign_cabinet(): void
    {
        $owner = $this->createSubscriberUser(withPermission: true);
        $intruder = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($owner, 'Foreign');

        $this->actingAs($intruder)
            ->get("/panel/wb/price-calc/cabinets/{$cabinet->id}")
            ->assertForbidden();
    }

    public function test_sync_products_passes_cabinet_id_from_web_panel(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'Sync Cabinet');

        $this->mock(WbPriceCalculationService::class, function ($mock): void {
            $mock->shouldReceive('getAllCards')
                ->once()
                ->andReturn(['httpCode' => 200, 'body' => json_encode(['cards' => []])]);
            $mock->shouldReceive('parseApiResponse')
                ->once()
                ->andReturn(['success' => true, 'data' => ['cards' => []]]);
        });

        $this->actingAs($user)
            ->post("/panel/wb/price-calc/cabinets/{$cabinet->id}/sync", [], [
                'HTTP_ACCEPT' => 'text/html, application/json',
                'CONTENT_TYPE' => 'application/json',
            ])
            ->assertRedirect()
            ->assertSessionHas('success')
            ->assertSessionMissing('error');

        $this->assertStringContainsString('Товары не найдены', session('success'));
    }

    public function test_destroy_cabinet_redirects_with_success(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'Delete Me');

        $this->actingAs($user)
            ->delete("/panel/wb/price-calc/cabinets/{$cabinet->id}")
            ->assertRedirect('/panel/wb/price-calc')
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('wb_price_cabinets', ['id' => $cabinet->id]);
    }

    private function createSubscriberUser(bool $withPermission = false): User
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
        ]);

        $user->assignRole('Подписчик');

        if ($withPermission) {
            $user->givePermissionTo('subscriber wb price calculator');
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
            'limits_plan' => ['price_calc_clients' => 5],
        ]);

        return $user;
    }

    private function createCabinet(User $user, string $name): PriceCalculationCabinets
    {
        $cabinet = PriceCalculationCabinets::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'apikey' => 'test-api-key',
        ]);

        PriceCalculationV2Settings::query()->create([
            'cabinet_id' => $cabinet->id,
            'hide_sizes' => true,
        ]);

        return $cabinet;
    }

    private function setupPriceCalcSchema(): void
    {
        if (! Schema::hasTable('wb_price_cabinets')) {
            Schema::create('wb_price_cabinets', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('name');
                $table->text('apikey')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('wb_price_calc_v2_settings')) {
            Schema::create('wb_price_calc_v2_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('cabinet_id')->index();
                $table->string('maintenance_type')->default('transfer');
                $table->string('buyout_scope')->default('cabinet');
                $table->boolean('use_localization_index')->default(false);
                $table->boolean('use_storage')->default(false);
                $table->boolean('use_irp')->default(false);
                $table->string('commission_source')->default('fbs');
                $table->string('acquiring_source')->default('manual');
                $table->boolean('hide_sizes')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('wb_price_calc_v3_data')) {
            Schema::create('wb_price_calc_v3_data', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('cabinet_id')->index();
                $table->string('brand')->nullable();
                $table->string('subject_name')->nullable();
                $table->string('vendor_code')->nullable();
                $table->string('size')->nullable();
                $table->string('barcode')->nullable();
                $table->unsignedBigInteger('nm_id')->nullable();
                $table->decimal('volume_liters', 10, 3)->nullable();
                $table->decimal('extra_liters', 10, 3)->nullable();
                $table->decimal('cost_price', 12, 2)->nullable();
                $table->decimal('margin_percent', 6, 2)->nullable();
                $table->decimal('fulfillment_fee', 12, 2)->nullable();
                $table->decimal('maintenance_percent', 6, 2)->nullable();
                $table->decimal('stop_price', 12, 2)->nullable();
                $table->decimal('avg_base_logistics', 12, 2)->nullable();
                $table->decimal('avg_extra_liter_logistics', 12, 2)->nullable();
                $table->decimal('localization_index', 8, 4)->default(1);
                $table->decimal('avg_logistics', 12, 2)->nullable();
                $table->decimal('reverse_logistics_cost_gt_1_0_l', 12, 2)->nullable();
                $table->decimal('reverse_logistics_cost_0_801_1_0_l', 12, 2)->nullable();
                $table->decimal('reverse_logistics_cost_0_601_0_8_l', 12, 2)->nullable();
                $table->decimal('reverse_logistics_cost_0_401_0_6_l', 12, 2)->nullable();
                $table->decimal('reverse_logistics_cost_0_201_0_4_l', 12, 2)->nullable();
                $table->decimal('reverse_logistics_cost_0_001_0_2_l', 12, 2)->nullable();
                $table->decimal('return_rate_gt_1_1_l', 8, 4)->nullable();
                $table->decimal('return_rate_0_801_1_0_l', 8, 4)->nullable();
                $table->decimal('return_rate_0_601_0_8_l', 8, 4)->nullable();
                $table->decimal('return_rate_0_401_0_6_l', 8, 4)->nullable();
                $table->decimal('return_rate_0_201_0_4_l', 8, 4)->nullable();
                $table->decimal('return_rate_0_001_0_2_l', 8, 4)->nullable();
                $table->decimal('return_cost', 12, 2)->nullable();
                $table->decimal('buyout_percent', 6, 2)->nullable();
                $table->decimal('total_logistics', 12, 2)->nullable();
                $table->decimal('storage_cost', 12, 2)->nullable();
                $table->unsignedInteger('sales_count')->nullable();
                $table->decimal('storage_per_sale', 12, 2)->nullable();
                $table->decimal('advertising_percent', 6, 2)->nullable();
                $table->decimal('wb_commission_percent', 6, 2)->nullable();
                $table->decimal('options_constructor_percent_sales', 6, 2)->nullable();
                $table->decimal('options_constructor_percent_transfer', 6, 2)->nullable();
                $table->decimal('acquiring_percent', 6, 2)->nullable();
                $table->decimal('tax_percent', 6, 2)->nullable();
                $table->decimal('maintenance_percent_sales', 6, 2)->nullable();
                $table->decimal('irp', 8, 4)->nullable();
                $table->decimal('commission_plus_acquiring', 6, 2)->nullable();
                $table->decimal('standard_discount_percent', 6, 2)->nullable();
                $table->decimal('promotion_percent', 6, 2)->nullable();
                $table->decimal('min_price_promo', 12, 3)->nullable();
                $table->decimal('standard_price', 12, 2)->nullable();
                $table->decimal('price_before_discount', 12, 2)->nullable();
                $table->softDeletes();
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