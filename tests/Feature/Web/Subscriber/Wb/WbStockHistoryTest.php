<?php

namespace Tests\Feature\Web\Subscriber\Wb;

use App\Console\Commands\WbOrderHistorySnapshotCommand;
use App\Console\Commands\WbStockHistorySnapshotCommand;
use App\Enums\WbHistoryLoadStatus;
use App\Jobs\Wb\StockHistory\ProcessWbOrderHistoryBackfillJob;
use App\Jobs\Wb\StockHistory\ProcessWbOrderHistorySnapshotJob;
use App\Jobs\Wb\StockHistory\ProcessWbStockHistoryBackfillJob;
use App\Jobs\Wb\StockHistory\ProcessWbStockHistorySnapshotJob;
use App\Models\Subscribers\Subscribers;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\Subscribers\Wb\StockHistory\WbStockHistoryDay;
use App\Models\Subscribers\Wb\StockHistory\WbStockHistoryItem;
use App\Models\Subscribers\Wb\StockHistory\WbStockHistoryProduct;
use App\Models\Subscribers\Wb\StockHistory\WbStockHistorySetting;
use App\Models\Subscribers\Wb\StockHistory\WbStockHistoryWarehouse;
use App\Models\Subscribers\Wb\WbCabinet;
use App\Models\User;
use App\Services\Wb\StockHistory\WbStockHistoryApiClient;
use App\Services\Wb\StockHistory\WbStockHistorySyncService;
use App\Support\Wb\WbStockHistoryCalendar;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Web\Auth\WebAuthTestCase;

class WbStockHistoryTest extends WebAuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setupSchema();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::firstOrCreate([
            'name' => 'subscriber wb stock history',
            'guard_name' => 'web',
        ]);
    }

    public function test_guest_cannot_access_index(): void
    {
        $this->get('/panel/wb/stock-history')->assertRedirect('/login');
    }

    public function test_user_without_permission_cannot_access_index(): void
    {
        $user = $this->createSubscriberUser();

        $this->actingAs($user)
            ->get('/panel/wb/stock-history')
            ->assertForbidden();
    }

    public function test_subscriber_without_cabinet_sees_placeholder(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);

        $this->actingAs($user)
            ->get('/panel/wb/stock-history')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Wb/Shared/NoCabinet')
                ->where('toolName', 'История остатков и заказов'));
    }

    public function test_index_renders_idle_workspace(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-12 12:00:00', 'Europe/Moscow'));
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user);

        $this->actingAs($user)
            ->get('/panel/wb/stock-history')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Wb/StockHistory/Index')
                ->where('cabinet.id', $cabinet->id)
                ->where('tab', 'stocks')
                ->where('tracking.stocks.has_history', false)
                ->where('tracking.stocks.tracking_enabled', false)
                ->where('tracking.orders.has_history', false)
                ->where('tracking.orders.tracking_enabled', false)
                ->where('filters.from', '2026-08-14')
                ->where('filters.to', '2026-09-12'));
    }

    public function test_period_to_is_clamped_to_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-12 12:00:00', 'Europe/Moscow'));
        $user = $this->createSubscriberUser(withPermission: true);
        $this->createCabinet($user);

        $this->actingAs($user)
            ->get('/panel/wb/stock-history?from=2026-09-10&to=2026-09-20')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.from', '2026-09-10')
                ->where('filters.to', '2026-09-12')
                ->where('dates', ['2026-09-10', '2026-09-11', '2026-09-12']));
    }

    public function test_orders_tab_is_independent(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user);
        $this->seedStock($cabinet, '2026-09-10', 12);

        $this->actingAs($user)
            ->get('/panel/wb/stock-history?tab=orders')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('tab', 'orders')
                ->where('tracking.stocks.has_history', true)
                ->where('tracking.orders.has_history', false)
                ->where('rows', []));
    }

    public function test_stock_load_dispatches_job(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-09-12 12:00:00', 'Europe/Moscow'));
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user);

        $this->actingAs($user)
            ->post('/panel/wb/stock-history/stocks/load')
            ->assertRedirect();

        Queue::assertPushed(ProcessWbStockHistoryBackfillJob::class, function (ProcessWbStockHistoryBackfillJob $job) use ($cabinet) {
            return $job->cabinetId === (int) $cabinet->id
                && $job->from === '2026-07-12'
                && $job->to === '2026-09-12';
        });

        $settings = WbStockHistorySetting::query()->where('cabinet_id', $cabinet->id)->first();
        $this->assertSame(WbHistoryLoadStatus::Loading, $settings->stocks_status);
    }

    public function test_stock_refresh_dispatches_force_job_without_enabling_tracking(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-09-12 12:00:00', 'Europe/Moscow'));
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user);
        WbStockHistorySetting::query()->create([
            'cabinet_id' => $cabinet->id,
            'stocks_tracking_enabled' => false,
            'orders_tracking_enabled' => false,
            'retention_days' => 90,
        ]);

        $this->actingAs($user)
            ->post('/panel/wb/stock-history/stocks/refresh', [
                'from' => '2026-08-20',
            ])
            ->assertRedirect();

        Queue::assertPushed(ProcessWbStockHistoryBackfillJob::class, function (ProcessWbStockHistoryBackfillJob $job) use ($cabinet) {
            return $job->cabinetId === (int) $cabinet->id
                && $job->from === '2026-08-20'
                && $job->to === '2026-09-12'
                && $job->force === true
                && $job->enableTracking === false;
        });

        $settings = WbStockHistorySetting::query()->where('cabinet_id', $cabinet->id)->first();
        $this->assertFalse($settings->stocks_tracking_enabled);
        $this->assertSame(WbHistoryLoadStatus::Loading, $settings->stocks_status);
    }

    public function test_start_and_stop_stocks_tracking(): void
    {
        Queue::fake();
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user);
        $this->seedStock($cabinet, '2026-09-10', 12);

        $this->actingAs($user)
            ->post('/panel/wb/stock-history/stocks/start')
            ->assertRedirect();

        $settings = WbStockHistorySetting::query()->where('cabinet_id', $cabinet->id)->first();
        $this->assertTrue($settings->stocks_tracking_enabled);
        $this->assertSame(WbHistoryLoadStatus::Active, $settings->stocks_status);

        $this->actingAs($user)
            ->post('/panel/wb/stock-history/stocks/stop')
            ->assertRedirect();

        $settings->refresh();
        $this->assertFalse($settings->stocks_tracking_enabled);
        $this->assertSame(WbHistoryLoadStatus::Idle, $settings->stocks_status);
        $this->assertTrue(WbStockHistoryItem::query()->where('cabinet_id', $cabinet->id)->exists());

        Artisan::call(WbStockHistorySnapshotCommand::class);
        Queue::assertNotPushed(ProcessWbStockHistorySnapshotJob::class);
    }

    public function test_start_and_stop_orders_tracking(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user);

        $this->actingAs($user)
            ->post('/panel/wb/stock-history/orders/start')
            ->assertRedirect();

        $settings = WbStockHistorySetting::query()->where('cabinet_id', $cabinet->id)->first();
        $this->assertTrue($settings->orders_tracking_enabled);
        $this->assertSame(WbHistoryLoadStatus::Active, $settings->orders_status);

        $this->actingAs($user)
            ->post('/panel/wb/stock-history/orders/stop')
            ->assertRedirect();

        $settings->refresh();
        $this->assertFalse($settings->orders_tracking_enabled);
        $this->assertSame(WbHistoryLoadStatus::Idle, $settings->orders_status);
    }

    public function test_load_always_uses_two_calendar_months_until_today(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-09-14 12:00:00', 'Europe/Moscow'));
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user);

        $this->actingAs($user)
            ->post('/panel/wb/stock-history/stocks/load', [
                'from' => '2026-07-13',
            ])
            ->assertRedirect();

        Queue::assertPushed(ProcessWbStockHistoryBackfillJob::class, function (ProcessWbStockHistoryBackfillJob $job) use ($cabinet) {
            return $job->cabinetId === (int) $cabinet->id
                && $job->from === '2026-07-14'
                && $job->to === '2026-09-14';
        });
    }

    public function test_order_load_ignores_client_dates_and_uses_two_months(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-09-12 12:00:00', 'Europe/Moscow'));
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user);

        $this->actingAs($user)
            ->post('/panel/wb/stock-history/orders/load', [
                'from' => '2026-08-20',
                'to' => '2026-08-25',
            ])
            ->assertRedirect();

        Queue::assertPushed(ProcessWbOrderHistoryBackfillJob::class, function (ProcessWbOrderHistoryBackfillJob $job) use ($cabinet) {
            return $job->cabinetId === (int) $cabinet->id
                && $job->from === '2026-07-12'
                && $job->to === '2026-09-12';
        });
    }

    public function test_backfill_imports_csv_and_keeps_empty_as_missing(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-12 12:00:00', 'Europe/Moscow'));
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user);
        $this->mockApi();

        $job = new ProcessWbStockHistoryBackfillJob((int) $cabinet->id, '2026-09-10', '2026-09-11');
        $job->handle(app(WbStockHistorySyncService::class));

        $this->assertDatabaseHas('wb_stock_history_items', [
            'cabinet_id' => $cabinet->id,
            'nm_id' => 111,
            'chrt_id' => 22,
            'warehouse_key' => '_total',
            'stock_date' => '2026-09-10',
            'qty' => 15,
        ]);
        $this->assertDatabaseHas('wb_stock_history_items', [
            'cabinet_id' => $cabinet->id,
            'nm_id' => 111,
            'stock_date' => '2026-09-11',
            'qty' => 0,
        ]);
        $this->assertFalse(
            WbStockHistoryItem::query()
                ->where('cabinet_id', $cabinet->id)
                ->where('stock_date', '2026-09-09')
                ->exists()
        );
        $this->assertFalse(
            WbStockHistoryItem::query()
                ->where('cabinet_id', $cabinet->id)
                ->where('warehouse_key', '!=', '_total')
                ->exists()
        );

        $settings = WbStockHistorySetting::query()->where('cabinet_id', $cabinet->id)->first();
        $this->assertTrue($settings->stocks_tracking_enabled);
        $this->assertSame(WbHistoryLoadStatus::Active, $settings->stocks_status);

        $this->assertDatabaseHas('wb_stock_history_items', [
            'cabinet_id' => $cabinet->id,
            'nm_id' => 111,
            'stock_date' => '2026-09-12',
            'warehouse_key' => '_total',
            'qty' => 47,
        ]);

        $this->actingAs($user)
            ->get('/panel/wb/stock-history?from=2026-09-10&to=2026-09-12')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('rows.0.series', [15, 0, 47])
                ->where('rows.0.quantity', 47)
                ->where('rows.0.in_way_to_client', 5)
                ->where('rows.0.in_way_from_client', 1)
                ->where('rows.0.image_url', 'https://basket-01.wbbasket.ru/vol0/part0/111/images/c246x328/1.webp')
                ->missing('rows.0.warehouse_name')
                ->missing('rows.0.warehouse_count'));
    }

    public function test_current_stocks_sum_rows_even_with_placeholder_warehouse(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-12 12:00:00', 'Europe/Moscow'));
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user);
        $this->mockApi(currentStocks: [
            [
                'nmId' => 111,
                'chrtId' => 22,
                'warehouseId' => -999999,
                'warehouseName' => 'Склад WB',
                'quantity' => 9,
                'inWayToClient' => 2,
                'inWayFromClient' => 1,
            ],
        ]);

        $job = new ProcessWbStockHistoryBackfillJob((int) $cabinet->id, '2026-09-10', '2026-09-11');
        $job->handle(app(WbStockHistorySyncService::class));

        $this->actingAs($user)
            ->get('/panel/wb/stock-history?from=2026-09-10&to=2026-09-11')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('rows.0.quantity', 9)
                ->where('rows.0.in_way_to_client', 2)
                ->where('rows.0.in_way_from_client', 1));
    }

    public function test_repeat_import_does_not_duplicate_stock_rows(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-12 12:00:00', 'Europe/Moscow'));
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user);
        $this->mockApi();

        $sync = app(WbStockHistorySyncService::class);
        $sync->importStockHistory($cabinet, '2026-09-10', '2026-09-11', false);
        $sync->importStockHistory($cabinet, '2026-09-10', '2026-09-11', false);

        $this->assertSame(2, WbStockHistoryItem::query()->where('cabinet_id', $cabinet->id)->count());
        $this->assertSame(2, WbStockHistoryDay::query()->where('cabinet_id', $cabinet->id)->count());
    }

    public function test_force_import_replaces_snapshot_rows_with_wb_history(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-12 12:00:00', 'Europe/Moscow'));
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user);
        $this->seedStock($cabinet, '2026-09-10', 99);
        $this->mockApi();

        $job = new ProcessWbStockHistoryBackfillJob((int) $cabinet->id, '2026-09-10', '2026-09-11', true, false);
        $job->handle(app(WbStockHistorySyncService::class));

        $this->assertFalse(
            WbStockHistoryItem::query()
                ->where('cabinet_id', $cabinet->id)
                ->where('warehouse_key', 'Коледино')
                ->where('stock_date', '2026-09-10')
                ->exists()
        );
        $this->assertDatabaseHas('wb_stock_history_items', [
            'cabinet_id' => $cabinet->id,
            'nm_id' => 111,
            'chrt_id' => 22,
            'warehouse_key' => '_total',
            'stock_date' => '2026-09-10',
            'qty' => 15,
        ]);

        $settings = WbStockHistorySetting::query()->where('cabinet_id', $cabinet->id)->first();
        $this->assertFalse($settings->stocks_tracking_enabled);
        $this->assertSame(WbHistoryLoadStatus::Idle, $settings->stocks_status);
    }

    public function test_orders_count_unique_srid_and_include_cancelled(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-12 12:00:00', 'Europe/Moscow'));
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user);
        $this->mockApi(orders: [
            [
                'srid' => 'a1',
                'date' => '2026-09-10T10:00:00',
                'nmId' => 111,
                'techSize' => '42',
                'barcode' => '200',
                'supplierArticle' => 'ART-1',
                'subject' => 'Футболки',
                'isCancel' => false,
            ],
            [
                'srid' => 'a1',
                'date' => '2026-09-10T11:00:00',
                'nmId' => 111,
                'techSize' => '42',
                'barcode' => '200',
                'supplierArticle' => 'ART-1',
                'subject' => 'Футболки',
                'isCancel' => true,
            ],
            [
                'srid' => 'b2',
                'date' => '2026-09-10T12:00:00',
                'nmId' => 111,
                'techSize' => '42',
                'barcode' => '200',
                'supplierArticle' => 'ART-1',
                'subject' => 'Футболки',
                'isCancel' => true,
            ],
        ]);

        $job = new ProcessWbOrderHistoryBackfillJob((int) $cabinet->id, '2026-09-10', '2026-09-11');
        $job->handle(app(WbStockHistorySyncService::class));

        $this->assertDatabaseHas('wb_order_history_items', [
            'cabinet_id' => $cabinet->id,
            'nm_id' => 111,
            'barcode' => '200',
            'order_date' => '2026-09-10',
            'orders_count' => 2,
        ]);

        $this->actingAs($user)
            ->get('/panel/wb/stock-history?tab=orders&from=2026-09-10&to=2026-09-11')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('tab', 'orders')
                ->where('rows.0.series', [2, 0])
                ->where('rows.0.barcode', '200')
                ->where('rows.0.image_url', 'https://basket-01.wbbasket.ru/vol0/part0/111/images/c246x328/1.webp'));
    }

    public function test_snapshot_writes_today_from_current_stocks_and_overwrites(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-12 12:00:00', 'Europe/Moscow'));
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user);
        WbStockHistorySetting::query()->create([
            'cabinet_id' => $cabinet->id,
            'stocks_tracking_enabled' => true,
            'retention_days' => 90,
        ]);

        $this->mockApi();
        $job = new ProcessWbStockHistorySnapshotJob((int) $cabinet->id);
        $job->handle(app(WbStockHistorySyncService::class));

        $this->assertDatabaseHas('wb_stock_history_items', [
            'cabinet_id' => $cabinet->id,
            'nm_id' => 111,
            'chrt_id' => 22,
            'warehouse_key' => '_total',
            'stock_date' => '2026-09-11',
            'qty' => 0,
        ]);
        $this->assertDatabaseHas('wb_stock_history_items', [
            'cabinet_id' => $cabinet->id,
            'nm_id' => 111,
            'chrt_id' => 22,
            'warehouse_key' => '_total',
            'stock_date' => '2026-09-12',
            'qty' => 47,
        ]);

        $this->mockApi(currentStocks: [[
            'nmId' => 111,
            'chrtId' => 22,
            'warehouseId' => 1,
            'warehouseName' => 'Коледино',
            'quantity' => 10,
            'inWayToClient' => 0,
            'inWayFromClient' => 0,
        ]]);
        $job->handle(app(WbStockHistorySyncService::class));

        $this->assertSame(10, (int) WbStockHistoryItem::query()
            ->where('cabinet_id', $cabinet->id)
            ->where('stock_date', '2026-09-12')
            ->where('warehouse_key', '_total')
            ->value('qty'));
        $this->assertSame(1, WbStockHistoryItem::query()
            ->where('cabinet_id', $cabinet->id)
            ->where('stock_date', '2026-09-12')
            ->count());
        $this->assertSame(0, (int) WbStockHistoryItem::query()
            ->where('cabinet_id', $cabinet->id)
            ->where('stock_date', '2026-09-11')
            ->value('qty'));
    }

    public function test_snapshot_commands_skip_disabled_cabinets(): void
    {
        Queue::fake();
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user);
        WbStockHistorySetting::query()->create([
            'cabinet_id' => $cabinet->id,
            'stocks_tracking_enabled' => false,
            'orders_tracking_enabled' => true,
            'retention_days' => 90,
        ]);

        Artisan::call(WbStockHistorySnapshotCommand::class);
        Queue::assertNotPushed(ProcessWbStockHistorySnapshotJob::class);

        Artisan::call(WbOrderHistorySnapshotCommand::class);
        Queue::assertPushed(ProcessWbOrderHistorySnapshotJob::class, function (ProcessWbOrderHistorySnapshotJob $job) use ($cabinet) {
            return $job->cabinetId === (int) $cabinet->id;
        });
    }

    public function test_permission_is_registered_in_roles_seeder(): void
    {
        $seeder = file_get_contents(base_path('database/seeders/Roles.php'));
        $this->assertStringContainsString('subscriber wb stock history', (string) $seeder);
    }

    public function test_yesterday_date_uses_moscow_calendar(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-12 00:30:00', 'Europe/Moscow'));
        $this->assertSame('2026-09-11', WbStockHistoryCalendar::yesterdayDate());
        $this->assertSame('2026-09-12', WbStockHistoryCalendar::todayDate());
        $this->assertSame('2026-07-12', WbStockHistoryCalendar::earliestLoadDate());
        Carbon::setTestNow(Carbon::parse('2026-09-14 12:00:00', 'Europe/Moscow'));
        $this->assertSame('2026-07-14', WbStockHistoryCalendar::earliestLoadDate());
    }

    public function test_csv_parser_keeps_blank_cell_out_of_stocks(): void
    {
        $csv = implode("\n", [
            'VendorCode;Name;NmID;SubjectName;SizeName;ChrtID;OfficeName;10.09.2026;11.09.2026',
            'ART-1;Футболка;111;Футболки;42;22;Коледино;15;',
            'ART-1;Футболка;111;Футболки;42;22;Коледино;;0',
        ]);

        $rows = app(WbStockHistoryApiClient::class)->parseDailyStockCsv($csv);

        $this->assertSame(15, $rows[0]['stocks']['2026-09-10']);
        $this->assertArrayNotHasKey('2026-09-11', $rows[0]['stocks']);
        $this->assertSame(0, $rows[1]['stocks']['2026-09-11']);
        $this->assertArrayNotHasKey('2026-09-10', $rows[1]['stocks']);
    }

    /**
     * @param  list<array<string, mixed>>|null  $orders
     * @param  list<array<string, mixed>>|null  $currentStocks
     */
    private function mockApi(?array $orders = null, ?array $currentStocks = null): void
    {
        $mock = Mockery::mock(WbStockHistoryApiClient::class);

        $mock->shouldReceive('fetchCards')->andReturn([
            [
                'nmId' => 111,
                'vendorCode' => 'ART-1',
                'subject' => 'Футболки',
                'name' => 'Футболка белая',
                'sizes' => [[
                    'chrtId' => 22,
                    'techSize' => '42',
                    'barcode' => '200',
                ]],
            ],
        ]);

        $mock->shouldReceive('fetchDailyStockHistory')->andReturn([
            [
                'vendorCode' => 'ART-1',
                'name' => 'Футболка белая',
                'nmId' => 111,
                'subjectName' => 'Футболки',
                'sizeName' => '42',
                'chrtId' => 22,
                'officeName' => 'Коледино',
                'warehouseId' => 0,
                'stocks' => [
                    '2026-09-10' => 15,
                    '2026-09-11' => 0,
                ],
            ],
        ]);

        $mock->shouldReceive('fetchCurrentStocks')->andReturn($currentStocks ?? [
            [
                'nmId' => 111,
                'chrtId' => 22,
                'warehouseId' => 1,
                'warehouseName' => 'Коледино',
                'quantity' => 40,
                'inWayToClient' => 3,
                'inWayFromClient' => 1,
            ],
            [
                'nmId' => 111,
                'chrtId' => 22,
                'warehouseId' => 2,
                'warehouseName' => 'Казань',
                'quantity' => 7,
                'inWayToClient' => 0,
                'inWayFromClient' => 0,
            ],
            [
                'nmId' => 111,
                'chrtId' => 22,
                'warehouseId' => 3,
                'warehouseName' => 'Пустой',
                'quantity' => 0,
                'inWayToClient' => 2,
                'inWayFromClient' => 0,
            ],
        ]);

        $mock->shouldReceive('fetchOrders')->andReturn($orders ?? []);

        $this->app->instance(WbStockHistoryApiClient::class, $mock);
    }

    private function seedStock(WbCabinet $cabinet, string $date, int $qty): void
    {
        WbStockHistorySetting::query()->firstOrCreate(
            ['cabinet_id' => $cabinet->id],
            ['retention_days' => 90],
        );
        WbStockHistoryProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 111,
            'chrt_id' => 22,
            'vendor_code' => 'ART-1',
            'subject' => 'Футболки',
            'tech_size' => '42',
            'barcode' => '200',
            'is_active' => true,
        ]);
        WbStockHistoryWarehouse::query()->create([
            'cabinet_id' => $cabinet->id,
            'warehouse_key' => 'Коледино',
            'warehouse_name' => 'Коледино',
        ]);
        WbStockHistoryDay::query()->create([
            'cabinet_id' => $cabinet->id,
            'stock_date' => $date,
        ]);
        WbStockHistoryItem::query()->create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 111,
            'chrt_id' => 22,
            'warehouse_key' => 'Коледино',
            'stock_date' => $date,
            'qty' => $qty,
        ]);
    }

    private function createSubscriberUser(bool $withPermission = false): User
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
        ]);
        $user->assignRole('Подписчик');
        if ($withPermission) {
            $user->givePermissionTo('subscriber wb stock history');
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

    private function createCabinet(User $user): WbCabinet
    {
        $cabinet = WbCabinet::query()->create([
            'user_id' => $user->id,
            'name' => 'WB кабинет',
            'apikey' => 'test-api-key',
            'api_key_hash' => hash('sha256', 'test-api-key'),
        ]);
        $user->forceFill(['selected_wb_cabinet_id' => $cabinet->id])->save();

        return $cabinet;
    }

    private function setupSchema(): void
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
                $table->timestamps();
            });
        }

        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'selected_wb_cabinet_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedBigInteger('selected_wb_cabinet_id')->nullable();
            });
        }

        if (! Schema::hasTable('wb_cabinets')) {
            Schema::create('wb_cabinets', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('name');
                $table->text('apikey')->nullable();
                $table->string('api_key_hash', 64)->nullable();
                $table->integer('error_code')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('wb_stock_history_settings')) {
            Schema::create('wb_stock_history_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('cabinet_id')->unique();
                $table->unsignedTinyInteger('retention_days')->default(90);
                $table->boolean('stocks_tracking_enabled')->default(false);
                $table->boolean('orders_tracking_enabled')->default(false);
                $table->string('stocks_status', 32)->default('idle');
                $table->string('orders_status', 32)->default('idle');
                $table->text('stocks_last_error')->nullable();
                $table->text('orders_last_error')->nullable();
                $table->timestamp('products_synced_at')->nullable();
                $table->unsignedInteger('products_count')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('wb_stock_history_products')) {
            Schema::create('wb_stock_history_products', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('cabinet_id');
                $table->unsignedBigInteger('nm_id');
                $table->unsignedBigInteger('chrt_id')->default(0);
                $table->string('vendor_code')->nullable();
                $table->string('subject')->nullable();
                $table->string('name')->nullable();
                $table->string('tech_size')->nullable();
                $table->string('barcode')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->unique(['cabinet_id', 'nm_id', 'chrt_id'], 'wb_stock_hist_products_unique');
            });
        }

        if (! Schema::hasTable('wb_stock_history_warehouses')) {
            Schema::create('wb_stock_history_warehouses', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('cabinet_id');
                $table->string('warehouse_key', 191);
                $table->unsignedBigInteger('warehouse_id')->nullable();
                $table->string('warehouse_name');
                $table->timestamps();
                $table->unique(['cabinet_id', 'warehouse_key'], 'wb_stock_hist_wh_unique');
            });
        }

        if (! Schema::hasTable('wb_stock_history_days')) {
            Schema::create('wb_stock_history_days', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('cabinet_id');
                $table->date('stock_date');
                $table->timestamps();
                $table->unique(['cabinet_id', 'stock_date'], 'wb_stock_hist_days_unique');
            });
        }

        if (! Schema::hasTable('wb_stock_history_items')) {
            Schema::create('wb_stock_history_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('cabinet_id');
                $table->unsignedBigInteger('nm_id');
                $table->unsignedBigInteger('chrt_id')->default(0);
                $table->string('warehouse_key', 191);
                $table->date('stock_date');
                $table->unsignedInteger('qty');
                $table->timestamps();
                $table->unique(
                    ['cabinet_id', 'nm_id', 'chrt_id', 'warehouse_key', 'stock_date'],
                    'wb_stock_hist_items_unique'
                );
            });
        }

        if (! Schema::hasTable('wb_order_history_days')) {
            Schema::create('wb_order_history_days', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('cabinet_id');
                $table->date('order_date');
                $table->timestamps();
                $table->unique(['cabinet_id', 'order_date'], 'wb_order_hist_days_unique');
            });
        }

        if (! Schema::hasTable('wb_order_history_items')) {
            Schema::create('wb_order_history_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('cabinet_id');
                $table->unsignedBigInteger('nm_id');
                $table->string('tech_size')->default('');
                $table->string('barcode')->default('');
                $table->string('vendor_code')->nullable();
                $table->string('subject')->nullable();
                $table->date('order_date');
                $table->unsignedInteger('orders_count')->default(0);
                $table->timestamps();
                $table->unique(
                    ['cabinet_id', 'nm_id', 'tech_size', 'barcode', 'order_date'],
                    'wb_order_hist_items_unique'
                );
            });
        }
    }
}
