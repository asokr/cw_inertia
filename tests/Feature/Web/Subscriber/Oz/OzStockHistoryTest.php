<?php

namespace Tests\Feature\Web\Subscriber\Oz;

use App\Console\Commands\OzStockHistorySnapshotCommand;
use App\Enums\OzStockHistorySnapshotStatus;
use App\Enums\OzStockHistoryTrackingStatus;
use App\Jobs\Oz\StockHistory\ProcessOzStockHistorySnapshotJob;
use App\Jobs\Oz\StockHistory\ProcessOzStockHistoryStartJob;
use App\Models\Subscribers\Oz\OzCabinet;
use App\Models\Subscribers\Oz\StockHistory\OzStockHistoryItem;
use App\Models\Subscribers\Oz\StockHistory\OzStockHistoryProduct;
use App\Models\Subscribers\Oz\StockHistory\OzStockHistorySetting;
use App\Models\Subscribers\Oz\StockHistory\OzStockHistorySnapshot;
use App\Models\Subscribers\Oz\StockHistory\OzStockHistoryWarehouse;
use App\Models\Subscribers\Subscribers;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\User;
use App\Services\Ozon\OzonApiService;
use App\Support\Oz\OzStockHistoryCalendar;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Web\Auth\WebAuthTestCase;

class OzStockHistoryTest extends WebAuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setupOzStockHistorySchema();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::firstOrCreate([
            'name' => 'subscriber oz stock history',
            'guard_name' => 'web',
        ]);
    }

    public function test_guest_cannot_access_index(): void
    {
        $this->get('/panel/oz/stock-history')->assertRedirect('/login');
    }

    public function test_user_without_permission_cannot_access_index(): void
    {
        $user = $this->createSubscriberUser();

        $this->actingAs($user)
            ->get('/panel/oz/stock-history')
            ->assertForbidden();
    }

    public function test_subscriber_without_cabinet_sees_placeholder(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);

        $this->actingAs($user)
            ->get('/panel/oz/stock-history')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Oz/Shared/NoCabinet')
                ->where('toolName', 'История остатков'));
    }

    public function test_index_renders_idle_settings_for_selected_cabinet(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user);

        $this->actingAs($user)
            ->get('/panel/oz/stock-history')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Oz/StockHistory/Index')
                ->where('cabinet.id', $cabinet->id)
                ->where('tracking.tracking_enabled', false)
                ->where('tracking.tracking_status', 'idle')
                ->where('tracking.retention_days', 90)
                ->where('tracking.has_history', false));
    }

    public function test_start_dispatches_job_and_sets_loading_status(): void
    {
        Queue::fake();
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user);

        $this->actingAs($user)
            ->post('/panel/oz/stock-history/start')
            ->assertRedirect();

        Queue::assertPushed(ProcessOzStockHistoryStartJob::class, function (ProcessOzStockHistoryStartJob $job) use ($cabinet) {
            return $job->cabinetId === (int) $cabinet->id;
        });

        $settings = OzStockHistorySetting::query()->where('cabinet_id', $cabinet->id)->first();
        $this->assertNotNull($settings);
        $this->assertFalse($settings->tracking_enabled);
        $this->assertSame(OzStockHistoryTrackingStatus::LoadingProducts, $settings->tracking_status);
    }

    public function test_start_without_catalog_does_not_enable_tracking(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user);
        $this->mockOzonApi(emptyCatalog: true);

        $this->actingAs($user)
            ->post('/panel/oz/stock-history/start')
            ->assertRedirect();

        $settings = OzStockHistorySetting::query()->where('cabinet_id', $cabinet->id)->first();
        $this->assertFalse($settings->tracking_enabled);
        $this->assertSame(OzStockHistoryTrackingStatus::Error, $settings->tracking_status);
        $this->assertSame('В кабинете нет товаров.', $settings->last_error);
        $this->assertSame(0, OzStockHistoryProduct::query()->count());
    }

    public function test_start_loads_products_and_yesterday_snapshot(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-05 12:00:00', 'Europe/Moscow'));
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user);
        $this->mockOzonApi();

        $this->actingAs($user)
            ->post('/panel/oz/stock-history/start')
            ->assertRedirect();

        $settings = OzStockHistorySetting::query()->where('cabinet_id', $cabinet->id)->first();
        $this->assertTrue($settings->tracking_enabled);
        $this->assertSame(OzStockHistoryTrackingStatus::Active, $settings->tracking_status);
        $this->assertSame(1, (int) $settings->products_count);

        $this->assertDatabaseHas('oz_stock_history_products', [
            'cabinet_id' => $cabinet->id,
            'sku' => 111,
            'offer_id' => 'ANG-Y-42',
            'is_active' => 1,
        ]);

        $this->assertDatabaseHas('oz_stock_history_items', [
            'cabinet_id' => $cabinet->id,
            'sku' => 111,
            'stock_date' => '2026-09-04',
            'qty' => 40,
        ]);

        $this->assertSame(0, OzStockHistoryItem::query()->where('qty', 0)->count());
    }

    public function test_stop_keeps_history_and_dispatcher_skips_cabinet(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-05 12:00:00', 'Europe/Moscow'));
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user);
        $this->mockOzonApi();
        $this->actingAs($user)->post('/panel/oz/stock-history/start');

        $this->actingAs($user)
            ->post('/panel/oz/stock-history/stop')
            ->assertRedirect();

        $settings = OzStockHistorySetting::query()->where('cabinet_id', $cabinet->id)->first();
        $this->assertFalse($settings->tracking_enabled);
        $this->assertSame(OzStockHistoryTrackingStatus::Idle, $settings->tracking_status);
        $this->assertTrue(OzStockHistoryItem::query()->where('cabinet_id', $cabinet->id)->exists());

        Queue::fake();
        Artisan::call(OzStockHistorySnapshotCommand::class);
        Queue::assertNothingPushed();
    }

    public function test_dispatcher_enqueues_enabled_cabinets_only(): void
    {
        Queue::fake();
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user);
        OzStockHistorySetting::query()->create([
            'cabinet_id' => $cabinet->id,
            'tracking_enabled' => true,
            'tracking_status' => OzStockHistoryTrackingStatus::Active,
            'retention_days' => 90,
        ]);

        Artisan::call('subscriber:oz-stock-history-snapshot');

        Queue::assertPushed(ProcessOzStockHistorySnapshotJob::class, function (ProcessOzStockHistorySnapshotJob $job) use ($cabinet) {
            return $job->cabinetId === (int) $cabinet->id;
        });
    }

    public function test_index_shows_all_saved_days_without_picking_a_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-05 12:00:00', 'Europe/Moscow'));
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user);
        $this->seedHistory($cabinet, '2026-09-01', 10, '10');
        $this->seedHistory($cabinet, '2026-09-04', 40, '10');

        $this->actingAs($user)
            ->get('/panel/oz/stock-history')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.from', '2026-09-01')
                ->where('filters.to', '2026-09-04')
                ->where('dates', ['2026-09-01', '2026-09-02', '2026-09-03', '2026-09-04'])
                ->where('products.0.series', [10, null, null, 40])
                ->where('products.0.qty', 40)
                ->where('productsMeta.per_page', 100));
    }

    public function test_search_and_hides_zero_products(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user);
        $this->seedHistory($cabinet, '2026-09-04');

        OzStockHistoryProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'sku' => 999,
            'offer_id' => 'ZERO',
            'name' => 'Пустой товар',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get('/panel/oz/stock-history?search=ANG&from=2026-09-04&to=2026-09-04')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('products', 1)
                ->where('products.0.offer_id', 'ANG-Y-42'));

        $this->actingAs($user)
            ->get('/panel/oz/stock-history?search=Пустой&from=2026-09-04&to=2026-09-04')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('products', 0));
    }

    public function test_product_detail_keeps_empty_warehouse_and_marks_stockout(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user);
        $this->seedHistory($cabinet, '2026-09-03', 12, '10');
        OzStockHistoryItem::query()->create([
            'cabinet_id' => $cabinet->id,
            'sku' => 111,
            'warehouse_key' => '10',
            'stock_date' => '2026-09-04',
            'qty' => 0,
        ]);

        OzStockHistoryWarehouse::query()->updateOrCreate(
            ['cabinet_id' => $cabinet->id, 'warehouse_key' => '11'],
            [
                'warehouse_id' => 11,
                'warehouse_name' => 'Хоругвино РФЦ',
                'cluster_id' => 1,
                'cluster_name' => 'Москва',
            ],
        );
        OzStockHistoryItem::query()->create([
            'cabinet_id' => $cabinet->id,
            'sku' => 111,
            'warehouse_key' => '11',
            'stock_date' => '2026-09-04',
            'qty' => 7,
        ]);

        $this->actingAs($user)
            ->get('/panel/oz/stock-history?from=2026-09-03&to=2026-09-04')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('products', 1)
                ->where('products.0.stockout', true)
                ->where('productsMeta.per_page', 100));

        $this->actingAs($user)
            ->getJson('/panel/oz/stock-history/products/111?from=2026-09-03&to=2026-09-04')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.clusters.0.name', 'Москва')
            ->assertJsonPath('data.clusters.0.qty', 7)
            ->assertJsonPath('data.clusters.0.warehouses.0.warehouse_name', 'Петровское РФЦ')
            ->assertJsonPath('data.clusters.0.warehouses.0.qty', 0)
            ->assertJsonPath('data.clusters.0.warehouses.1.warehouse_name', 'Хоругвино РФЦ')
            ->assertJsonPath('data.clusters.0.warehouses.1.qty', 7);
    }

    public function test_settings_reject_more_than_half_year_and_prune_on_shrink(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-05 12:00:00', 'Europe/Moscow'));
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user);
        $this->seedHistory($cabinet, '2026-01-01', 5, '10');
        $this->seedHistory($cabinet, '2026-09-04', 8, '10');

        $this->actingAs($user)
            ->put('/panel/oz/stock-history/settings', ['retention_days' => 181])
            ->assertSessionHasErrors('retention_days');

        $this->actingAs($user)
            ->put('/panel/oz/stock-history/settings', ['retention_days' => 30])
            ->assertRedirect();

        $this->assertSame(30, (int) OzStockHistorySetting::query()->where('cabinet_id', $cabinet->id)->value('retention_days'));
        $this->assertFalse(
            OzStockHistoryItem::query()->where('cabinet_id', $cabinet->id)->where('stock_date', '2026-01-01')->exists()
        );
        $this->assertTrue(
            OzStockHistoryItem::query()->where('cabinet_id', $cabinet->id)->where('stock_date', '2026-09-04')->exists()
        );
    }

    public function test_snapshot_writes_zero_only_after_known_pair(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-05 12:00:00', 'Europe/Moscow'));
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user);
        $this->mockOzonApi(qty: 15);

        $job = new ProcessOzStockHistorySnapshotJob((int) $cabinet->id, '2026-09-03', false, true);
        $job->handle(app(\App\Services\Oz\StockHistory\OzStockHistorySyncService::class));

        $this->assertSame(1, OzStockHistoryItem::query()->where('qty', 15)->count());
        $this->assertSame(0, OzStockHistoryItem::query()->where('qty', 0)->count());

        $this->mockOzonApi(qty: 0);
        $job = new ProcessOzStockHistorySnapshotJob((int) $cabinet->id, '2026-09-04', false, true);
        $job->handle(app(\App\Services\Oz\StockHistory\OzStockHistorySyncService::class));

        $this->assertDatabaseHas('oz_stock_history_items', [
            'cabinet_id' => $cabinet->id,
            'sku' => 111,
            'stock_date' => '2026-09-04',
            'qty' => 0,
        ]);
    }

    public function test_yesterday_date_uses_moscow_calendar(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-05 00:30:00', 'Europe/Moscow'));
        $this->assertSame('2026-09-04', OzStockHistoryCalendar::yesterdayDate());
    }

    public function test_permission_is_registered_in_roles_seeder(): void
    {
        $seeder = file_get_contents(base_path('database/seeders/Roles.php'));
        $this->assertStringContainsString('subscriber oz stock history', (string) $seeder);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function mockOzonApi(bool $emptyCatalog = false, int $qty = 40): void
    {
        $mock = Mockery::mock(OzonApiService::class);

        $mock->shouldReceive('getProductsList')->andReturn([
            'success' => true,
            'status' => 200,
            'data' => [
                'result' => [
                    'items' => $emptyCatalog ? [] : [[
                        'product_id' => 55,
                        'offer_id' => 'ANG-Y-42',
                    ]],
                    'last_id' => '',
                ],
            ],
        ]);

        $mock->shouldReceive('getProductsInfo')->andReturn([
            'success' => true,
            'status' => 200,
            'data' => [
                'items' => $emptyCatalog ? [] : [[
                    'id' => 55,
                    'offer_id' => 'ANG-Y-42',
                    'name' => 'Анги желтый 42',
                    'fbo_sku' => 111,
                    'primary_image' => 'https://example.test/img.jpg',
                ]],
            ],
        ]);

        $mock->shouldReceive('getClusterList')->andReturn([
            'success' => true,
            'status' => 200,
            'data' => [
                'clusters' => [[
                    'id' => 1,
                    'name' => 'Москва',
                    'logistic_clusters' => [[
                        'warehouses' => [[
                            'warehouse_id' => 10,
                            'name' => 'Петровское РФЦ',
                        ]],
                    ]],
                ]],
            ],
        ]);

        $mock->shouldReceive('getAnalyticsStocks')->andReturn([
            'success' => true,
            'status' => 200,
            'data' => [
                'items' => $emptyCatalog ? [] : [[
                    'sku' => 111,
                    'available_stock_count' => $qty,
                    'warehouse_id' => 10,
                    'warehouse_name' => 'Петровское РФЦ',
                    'cluster_id' => 1,
                    'cluster_name' => 'Москва',
                ]],
            ],
        ]);

        $this->app->instance(OzonApiService::class, $mock);
    }

    private function seedHistory(OzCabinet $cabinet, string $date, int $qty = 40, string $warehouseKey = '10'): void
    {
        OzStockHistorySetting::query()->firstOrCreate(
            ['cabinet_id' => $cabinet->id],
            [
                'retention_days' => 90,
                'tracking_enabled' => false,
                'tracking_status' => OzStockHistoryTrackingStatus::Idle,
            ],
        );

        OzStockHistoryProduct::query()->updateOrCreate(
            ['cabinet_id' => $cabinet->id, 'sku' => 111],
            [
                'product_id' => 55,
                'offer_id' => 'ANG-Y-42',
                'name' => 'Анги желтый 42',
                'is_active' => true,
            ],
        );

        OzStockHistoryWarehouse::query()->updateOrCreate(
            ['cabinet_id' => $cabinet->id, 'warehouse_key' => $warehouseKey],
            [
                'warehouse_id' => (int) $warehouseKey,
                'warehouse_name' => 'Петровское РФЦ',
                'cluster_id' => 1,
                'cluster_name' => 'Москва',
            ],
        );

        OzStockHistorySnapshot::query()->updateOrCreate(
            ['cabinet_id' => $cabinet->id, 'stock_date' => $date],
            [
                'status' => OzStockHistorySnapshotStatus::Done,
                'collected_at' => now(),
                'products_count' => 1,
                'rows_count' => 1,
            ],
        );

        OzStockHistoryItem::query()->updateOrCreate(
            [
                'cabinet_id' => $cabinet->id,
                'sku' => 111,
                'warehouse_key' => $warehouseKey,
                'stock_date' => $date,
            ],
            ['qty' => $qty],
        );

        $this->assertTrue(
            OzStockHistoryItem::query()
                ->where('cabinet_id', $cabinet->id)
                ->where('stock_date', $date)
                ->exists(),
            "Не записалась история за {$date}",
        );
    }

    private function createSubscriberUser(bool $withPermission = false): User
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
        ]);
        $user->assignRole('Подписчик');
        if ($withPermission) {
            $user->givePermissionTo('subscriber oz stock history');
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
            'limits_plan' => [],
        ]);

        return $user;
    }

    private function createCabinet(User $user): OzCabinet
    {
        $cabinet = OzCabinet::query()->create([
            'user_id' => $user->id,
            'name' => 'Ozon кабинет',
            'client_id' => 'client-'.uniqid(),
            'apikey' => 'test-api-key',
        ]);
        $user->forceFill(['selected_oz_cabinet_id' => $cabinet->id])->save();

        return $cabinet;
    }

    private function setupOzStockHistorySchema(): void
    {
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

        if (! Schema::hasTable('subscribers_plans')) {
            Schema::create('subscribers_plans', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->decimal('price', 10, 2)->default(0);
                $table->unsignedInteger('duration')->default(30);
                $table->json('limits_plan')->nullable();
                $table->json('permissions')->nullable();
                $table->unsignedTinyInteger('status')->default(1);
                $table->timestamps();
            });
            \Illuminate\Support\Facades\DB::table('subscribers_plans')->insert([
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
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('oz_cabinets')) {
            Schema::create('oz_cabinets', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('name');
                $table->string('client_id');
                $table->text('apikey')->nullable();
                $table->string('performance_client_id')->nullable();
                $table->text('performance_client_secret')->nullable();
                $table->text('last_sync_error')->nullable();
                $table->timestamps();
            });
        } else {
            if (! Schema::hasColumn('oz_cabinets', 'performance_client_id')) {
                Schema::table('oz_cabinets', function (Blueprint $table) {
                    $table->string('performance_client_id')->nullable();
                });
            }
            if (! Schema::hasColumn('oz_cabinets', 'performance_client_secret')) {
                Schema::table('oz_cabinets', function (Blueprint $table) {
                    $table->text('performance_client_secret')->nullable();
                });
            }
        }

        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'selected_oz_cabinet_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedBigInteger('selected_oz_cabinet_id')->nullable();
            });
        }

        if (! Schema::hasTable('oz_stock_history_settings')) {
            Schema::create('oz_stock_history_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('cabinet_id')->unique();
                $table->unsignedTinyInteger('retention_days')->default(90);
                $table->boolean('tracking_enabled')->default(false);
                $table->string('tracking_status', 32)->default('idle');
                $table->timestamp('products_synced_at')->nullable();
                $table->unsignedInteger('products_count')->default(0);
                $table->text('last_error')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('oz_stock_history_snapshots')) {
            Schema::create('oz_stock_history_snapshots', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('cabinet_id');
                $table->date('stock_date');
                $table->string('status', 32)->default('pending');
                $table->timestamp('collected_at')->nullable();
                $table->unsignedInteger('products_count')->default(0);
                $table->unsignedInteger('rows_count')->default(0);
                $table->text('error_message')->nullable();
                $table->timestamps();
                $table->unique(['cabinet_id', 'stock_date']);
            });
        }

        if (! Schema::hasTable('oz_stock_history_products')) {
            Schema::create('oz_stock_history_products', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('cabinet_id');
                $table->unsignedBigInteger('sku');
                $table->unsignedBigInteger('product_id')->nullable();
                $table->string('offer_id')->nullable();
                $table->string('name')->nullable();
                $table->string('image_url', 1024)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->unique(['cabinet_id', 'sku']);
            });
        }

        if (! Schema::hasTable('oz_stock_history_warehouses')) {
            Schema::create('oz_stock_history_warehouses', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('cabinet_id');
                $table->string('warehouse_key', 64);
                $table->unsignedBigInteger('warehouse_id')->nullable();
                $table->string('warehouse_name');
                $table->unsignedBigInteger('cluster_id')->nullable();
                $table->string('cluster_name')->nullable();
                $table->timestamps();
                $table->unique(['cabinet_id', 'warehouse_key']);
            });
        }

        if (! Schema::hasTable('oz_stock_history_items')) {
            Schema::create('oz_stock_history_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('cabinet_id');
                $table->unsignedBigInteger('sku');
                $table->string('warehouse_key', 64);
                $table->date('stock_date');
                $table->unsignedInteger('qty')->default(0);
                $table->timestamps();
                $table->unique(['cabinet_id', 'sku', 'warehouse_key', 'stock_date']);
            });
        }
    }
}
