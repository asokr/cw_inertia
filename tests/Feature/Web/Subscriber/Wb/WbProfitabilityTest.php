<?php

namespace Tests\Feature\Web\Subscriber\Wb;

use App\Jobs\ExportProfitabilityReportJob;
use App\Jobs\ProcessProfitabilityReport;
use App\Models\JobStatus;
use App\Models\Subscribers\Subscribers;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\Subscribers\Wb\Profitability\Item;
use App\Models\Subscribers\Wb\Profitability\ProfitabilityCabinet;
use App\Models\Subscribers\Wb\Profitability\Report;
use App\Models\Subscribers\Wb\WbCabinet;
use App\Models\User;
use App\Services\Subscriber\Wb\WbProfitabilityReportService;
use App\Services\Wb\ProfitabilityApiService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Web\Auth\WebAuthTestCase;

class WbProfitabilityTest extends WebAuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setupProfitabilitySchema();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::firstOrCreate([
            'name' => 'subscriber wb profitability',
            'guard_name' => 'web',
        ]);
    }

    public function test_guest_cannot_access_profitability_index(): void
    {
        $this->get('/panel/wb/profitability')->assertRedirect('/login');
    }

    public function test_user_without_permission_cannot_access_index(): void
    {
        $user = $this->createSubscriberUser();

        $this->actingAs($user)
            ->get('/panel/wb/profitability')
            ->assertForbidden();
    }

    public function test_subscriber_with_permission_sees_no_cabinet_without_unified_cabinet(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);

        $this->actingAs($user)
            ->get('/panel/wb/profitability')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Wb/Shared/NoCabinet')
                ->where('toolName', 'Рентабельность Wildberries'));
    }

    public function test_index_renders_workspace_for_selected_unified_cabinet(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Test Cabinet');

        $this->actingAs($user)
            ->get('/panel/wb/profitability')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Wb/Profitability/Cabinet/Show')
                ->where('cabinet.id', $cabinet->id)
                ->where('cabinet.name', 'Test Cabinet'));
    }

    public function test_cabinet_show_renders_for_owner(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'Report Cabinet');

        $this->actingAs($user)
            ->get("/panel/wb/profitability/cabinets/{$cabinet->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Wb/Profitability/Cabinet/Show')
                ->where('cabinet.id', $cabinet->id)
                ->has('jobStatus')
                ->has('groupMeta')
                ->has('itemsBaseUrl')
                ->missing('groups'));
    }

    public function test_cabinet_show_forbidden_for_foreign_cabinet(): void
    {
        $owner = $this->createSubscriberUser(withPermission: true);
        $intruder = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($owner, 'Foreign');

        $this->actingAs($intruder)
            ->get("/panel/wb/profitability/cabinets/{$cabinet->id}")
            ->assertForbidden();
    }

    public function test_destroy_cabinet_redirects_with_success(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'Delete Me');

        $this->actingAs($user)
            ->delete("/panel/wb/profitability/cabinets/{$cabinet->id}")
            ->assertRedirect('/panel/wb/profitability')
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('wb_profitability_cabinets', ['id' => $cabinet->id]);
    }

    public function test_store_report_dispatches_job_for_owner(): void
    {
        Queue::fake();

        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'Queue Cabinet');

        $this->actingAs($user)
            ->post("/panel/wb/profitability/cabinets/{$cabinet->id}/report", [
                'date_from' => '2026-01-01',
                'date_to' => '2026-01-15',
                'dop_rashod' => 100,
                'nalog_percent' => 6,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        Queue::assertPushed(ProcessProfitabilityReport::class);
    }

    public function test_store_report_works_with_json_request_like_inertia(): void
    {
        Queue::fake();

        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'Json Request Cabinet');

        $this->actingAs($user)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'text/html, application/xhtml+xml')
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->postJson("/panel/wb/profitability/cabinets/{$cabinet->id}/report", [
                'date_from' => '2026-01-01',
                'date_to' => '2026-01-15',
                'dop_rashod' => '',
                'nalog_percent' => '',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        Queue::assertPushed(ProcessProfitabilityReport::class);
    }

    public function test_store_report_rejects_while_already_processing(): void
    {
        Queue::fake();

        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'Busy Cabinet');

        JobStatus::query()->create([
            'job_name' => ProcessProfitabilityReport::class,
            'data' => [
                'cabinet_id' => $cabinet->id,
                'user_id' => $user->id,
                'stage' => 'fetching',
                'batch' => 1,
                'rows_loaded' => 1000,
                'waiting_for_api' => false,
                'started_at' => now()->toIso8601String(),
            ],
            'status' => 'processing',
            'error' => null,
        ]);

        $this->actingAs($user)
            ->post("/panel/wb/profitability/cabinets/{$cabinet->id}/report", [
                'date_from' => '2026-01-01',
                'date_to' => '2026-01-15',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        Queue::assertNothingPushed();
    }

    public function test_store_report_accepts_empty_optional_fields_from_form(): void
    {
        Queue::fake();

        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'Optional Fields Cabinet');

        $this->actingAs($user)
            ->post("/panel/wb/profitability/cabinets/{$cabinet->id}/report", [
                'date_from' => '2026-01-01',
                'date_to' => '2026-01-15',
                'dop_rashod' => '',
                'nalog_percent' => '',
            ])
            ->assertRedirect()
            ->assertSessionHas('success')
            ->assertSessionDoesntHaveErrors();

        Queue::assertPushed(ProcessProfitabilityReport::class);

        $this->assertDatabaseHas('job_statuses', [
            'job_name' => ProcessProfitabilityReport::class,
            'status' => 'processing',
        ]);

        $status = JobStatus::query()
            ->where('job_name', ProcessProfitabilityReport::class)
            ->latest()
            ->first();

        $this->assertSame('queued', $status->data['stage'] ?? null);
    }

    public function test_cabinet_show_returns_group_meta_without_items_payload(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'Report Cabinet');

        $report = Report::query()->create([
            'cabinet_id' => $cabinet->id,
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-15',
            'sales_quantity' => 1,
            'sales_amount' => 1000,
            'itog' => 500,
        ]);

        Item::query()->create([
            'report_id' => $report->id,
            'nm_id' => 123456,
            'sa_name' => 'TEST-001',
            'supplier_oper_name' => 'Продажа',
            'quantity' => 1,
            'sum_to_transfer' => 1000,
            'margin' => 500,
            'profitability_percent' => 50,
        ]);

        Item::query()->create([
            'report_id' => $report->id,
            'nm_id' => 123456,
            'sa_name' => 'TEST-001',
            'supplier_oper_name' => 'Логистика',
            'quantity' => 1,
            'sum_to_transfer' => -100,
            'margin' => -100,
            'profitability_percent' => 0,
        ]);

        Cache::flush();

        $this->actingAs($user)
            ->get("/panel/wb/profitability/cabinets/{$cabinet->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Wb/Profitability/Cabinet/Show')
                ->has('report')
                ->where('groupMeta.sales', 1)
                ->where('groupMeta.logistics', 1)
                ->where('groupMeta.returns', 0)
                ->missing('groups')
                ->has('itemsBaseUrl')
                ->has('widget'));
    }

    public function test_cabinet_items_endpoint_paginates_by_group(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'Items API Cabinet');

        $report = Report::query()->create([
            'cabinet_id' => $cabinet->id,
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-15',
            'sales_quantity' => 2,
            'sales_amount' => 2000,
            'itog' => 1000,
        ]);

        Item::query()->create([
            'report_id' => $report->id,
            'nm_id' => 111,
            'sa_name' => 'SALE-A',
            'supplier_oper_name' => 'Продажа',
            'quantity' => 1,
            'sum_to_transfer' => 1000,
            'margin' => 500,
            'profitability_percent' => 50,
        ]);

        Item::query()->create([
            'report_id' => $report->id,
            'nm_id' => 222,
            'sa_name' => 'SALE-B',
            'supplier_oper_name' => 'Продажа',
            'quantity' => 1,
            'sum_to_transfer' => 1000,
            'margin' => 400,
            'profitability_percent' => 40,
        ]);

        Item::query()->create([
            'report_id' => $report->id,
            'nm_id' => 111,
            'sa_name' => 'LOG-A',
            'supplier_oper_name' => 'Логистика',
            'quantity' => 1,
            'sum_to_transfer' => -50,
            'margin' => -50,
            'profitability_percent' => 0,
        ]);

        $this->actingAs($user)
            ->getJson("/panel/wb/profitability/cabinets/{$cabinet->id}/items?group=sales&per_page=1&page=1")
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.has_more', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.sa_name', 'SALE-A');

        $this->actingAs($user)
            ->getJson("/panel/wb/profitability/cabinets/{$cabinet->id}/items?group=logistics")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.sa_name', 'LOG-A');
    }

    public function test_cabinet_items_forbidden_for_foreign_cabinet(): void
    {
        $owner = $this->createSubscriberUser(withPermission: true);
        $intruder = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($owner, 'Foreign Items');

        $this->actingAs($intruder)
            ->getJson("/panel/wb/profitability/cabinets/{$cabinet->id}/items?group=sales")
            ->assertForbidden();
    }

    public function test_workspace_exposes_delivery_group_and_paginates_delivery_items(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Delivery Items Cabinet');

        $report = Report::query()->create([
            'cabinet_id' => $cabinet->id,
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-15',
            'sales_quantity' => 1,
            'sales_amount' => 1000,
            'delivery' => 80,
            'itog' => 920,
            'margin' => 920,
        ]);

        Item::query()->create([
            'report_id' => $report->id,
            'nm_id' => 111,
            'sa_name' => 'SALE-DEL',
            'supplier_oper_name' => 'Продажа',
            'quantity' => 1,
            'sum_to_transfer' => 1000,
            'delivery' => 80,
            'margin' => 920,
            'profitability_percent' => 92,
        ]);

        Item::query()->create([
            'report_id' => $report->id,
            'nm_id' => 111,
            'sa_name' => 'DEL-A',
            'supplier_oper_name' => 'Доставка',
            'reasoning' => 'До покупателя',
            'quantity' => 1,
            'sum_to_transfer' => 80,
            'logistics' => 80,
            'margin' => 0,
        ]);

        Cache::flush();

        $this->actingAs($user)
            ->get('/panel/wb/profitability')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Wb/Profitability/Cabinet/Show')
                ->where('groupMeta.delivery', 1)
                ->where('groupMeta.sales', 1)
                ->where('report.delivery', 80));

        $this->actingAs($user)
            ->getJson('/panel/wb/profitability/items?group=delivery')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.sa_name', 'DEL-A')
            ->assertJsonPath('data.0.reasoning', 'До покупателя')
            ->assertJsonPath('data.0.logistics', 80);
    }

    public function test_process_report_subtracts_delivery_from_totals_and_sale_margin(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Delivery Job Cabinet');

        $this->ensurePriceCalcV3Table();

        $api = \Mockery::mock(ProfitabilityApiService::class);
        $api->shouldReceive('getReportDetailByPeriod')
            ->once()
            ->andReturn([
                'success' => true,
                'code' => 200,
                'data' => [
                    [
                        'sellerOperName' => 'Продажа',
                        'forPay' => 1000,
                        'retailAmount' => 1200,
                        'quantity' => 1,
                        'nmId' => 111,
                        'vendorCode' => 'SKU-1',
                        'sku' => 'barcode-1',
                        'officeName' => 'Коледино',
                        'cashbackAmount' => 0,
                        'cashbackDiscount' => 0,
                    ],
                    [
                        'sellerOperName' => 'Доставка',
                        'deliveryService' => 80,
                        'forPay' => 0,
                        'quantity' => 1,
                        'nmId' => 111,
                        'vendorCode' => 'SKU-1',
                        'sku' => 'barcode-1',
                        'officeName' => 'Коледино',
                        'bonusTypeName' => 'До покупателя',
                    ],
                ],
            ]);

        $job = new ProcessProfitabilityReport(
            (int) $cabinet->id,
            '2026-01-01',
            '2026-01-15',
            (int) $user->id,
            0,
            0
        );
        $job->handle($api);

        $report = Report::query()->where('cabinet_id', $cabinet->id)->first();
        $this->assertNotNull($report);
        $this->assertEqualsWithDelta(80.0, (float) $report->delivery, 0.001);
        $this->assertEqualsWithDelta(1000.0, (float) $report->sales_amount, 0.001);
        $this->assertEqualsWithDelta(920.0, (float) $report->itog, 0.001);
        $this->assertEqualsWithDelta(920.0, (float) $report->margin, 0.001);

        $sale = Item::query()
            ->where('report_id', $report->id)
            ->where('supplier_oper_name', 'Продажа')
            ->first();
        $this->assertNotNull($sale);
        $this->assertEqualsWithDelta(80.0, (float) $sale->delivery, 0.001);
        $this->assertEqualsWithDelta(920.0, (float) $sale->margin, 0.001);

        $delivery = Item::query()
            ->where('report_id', $report->id)
            ->where('supplier_oper_name', 'Доставка')
            ->first();
        $this->assertNotNull($delivery);
        $this->assertEqualsWithDelta(80.0, (float) $delivery->sum_to_transfer, 0.001);
        $this->assertEqualsWithDelta(80.0, (float) $delivery->logistics, 0.001);
        $this->assertSame('До покупателя', $delivery->reasoning);
    }

    public function test_process_report_uses_for_pay_when_delivery_service_is_empty(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Delivery Fallback Cabinet');

        $this->ensurePriceCalcV3Table();

        $api = \Mockery::mock(ProfitabilityApiService::class);
        $api->shouldReceive('getReportDetailByPeriod')
            ->once()
            ->andReturn([
                'success' => true,
                'code' => 200,
                'data' => [
                    [
                        'sellerOperName' => 'Продажа',
                        'forPay' => 500,
                        'retailAmount' => 500,
                        'quantity' => 1,
                        'nmId' => 222,
                        'vendorCode' => 'SKU-2',
                        'sku' => 'barcode-2',
                        'officeName' => 'Электросталь',
                    ],
                    [
                        'sellerOperName' => 'Доставка',
                        'forPay' => 45,
                        'quantity' => 1,
                        'nmId' => 222,
                        'vendorCode' => 'SKU-2',
                        'sku' => 'barcode-2',
                        'officeName' => 'Электросталь',
                    ],
                ],
            ]);

        $job = new ProcessProfitabilityReport(
            (int) $cabinet->id,
            '2026-02-01',
            '2026-02-07',
            (int) $user->id
        );
        $job->handle($api);

        $report = Report::query()->where('cabinet_id', $cabinet->id)->first();
        $this->assertNotNull($report);
        $this->assertEqualsWithDelta(45.0, (float) $report->delivery, 0.001);
        $this->assertEqualsWithDelta(455.0, (float) $report->itog, 0.001);
    }

    public function test_workspace_exposes_delivery_correction_in_other_group(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Delivery Correction Items Cabinet');

        $report = Report::query()->create([
            'cabinet_id' => $cabinet->id,
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-15',
            'sales_quantity' => 1,
            'sales_amount' => 1000,
            'delivery' => 100,
            'itog' => 900,
            'margin' => 900,
        ]);

        Item::query()->create([
            'report_id' => $report->id,
            'nm_id' => 555,
            'sa_name' => 'DEL-CORR',
            'supplier_oper_name' => 'Коррекция стоимости доставки',
            'quantity' => 1,
            'sum_to_transfer' => 20,
            'logistics' => 20,
        ]);

        Cache::flush();

        $this->actingAs($user)
            ->get('/panel/wb/profitability')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('groupMeta.other', 1)
                ->where('report.delivery', 100));

        $this->actingAs($user)
            ->getJson('/panel/wb/profitability/items?group=other')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.sa_name', 'DEL-CORR')
            ->assertJsonPath('data.0.type', 'Коррекция доставки')
            ->assertJsonPath('data.0.sum_to_transfer', 20);
    }

    public function test_process_report_subtracts_delivery_correction_from_totals_and_sale_margin(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Delivery Correction Job Cabinet');

        $this->ensurePriceCalcV3Table();

        $api = \Mockery::mock(ProfitabilityApiService::class);
        $api->shouldReceive('getReportDetailByPeriod')
            ->once()
            ->andReturn([
                'success' => true,
                'code' => 200,
                'data' => [
                    [
                        'sellerOperName' => 'Продажа',
                        'forPay' => 1000,
                        'retailAmount' => 1200,
                        'quantity' => 1,
                        'nmId' => 555,
                        'vendorCode' => 'SKU-5',
                        'sku' => 'barcode-5',
                        'officeName' => 'Коледино',
                        'cashbackAmount' => 0,
                        'cashbackDiscount' => 0,
                    ],
                    [
                        'sellerOperName' => 'Доставка',
                        'deliveryService' => 80,
                        'forPay' => 0,
                        'quantity' => 1,
                        'nmId' => 555,
                        'vendorCode' => 'SKU-5',
                        'sku' => 'barcode-5',
                        'officeName' => 'Коледино',
                        'bonusTypeName' => 'До покупателя',
                    ],
                    [
                        'sellerOperName' => 'Коррекция стоимости доставки',
                        'deliveryService' => 20,
                        'forPay' => 0,
                        'quantity' => 1,
                        'nmId' => 555,
                        'vendorCode' => 'SKU-5',
                        'sku' => 'barcode-5',
                        'officeName' => 'Коледино',
                    ],
                ],
            ]);

        $job = new ProcessProfitabilityReport(
            (int) $cabinet->id,
            '2026-04-01',
            '2026-04-07',
            (int) $user->id
        );
        $job->handle($api);

        $report = Report::query()->where('cabinet_id', $cabinet->id)->first();
        $this->assertNotNull($report);
        $this->assertEqualsWithDelta(100.0, (float) $report->delivery, 0.001);
        $this->assertEqualsWithDelta(1000.0, (float) $report->sales_amount, 0.001);
        $this->assertEqualsWithDelta(900.0, (float) $report->itog, 0.001);
        $this->assertEqualsWithDelta(900.0, (float) $report->margin, 0.001);

        $sale = Item::query()
            ->where('report_id', $report->id)
            ->where('supplier_oper_name', 'Продажа')
            ->first();
        $this->assertNotNull($sale);
        $this->assertEqualsWithDelta(100.0, (float) $sale->delivery, 0.001);
        $this->assertEqualsWithDelta(900.0, (float) $sale->margin, 0.001);

        $correction = Item::query()
            ->where('report_id', $report->id)
            ->where('supplier_oper_name', 'Коррекция стоимости доставки')
            ->first();
        $this->assertNotNull($correction);
        $this->assertEqualsWithDelta(20.0, (float) $correction->sum_to_transfer, 0.001);
        $this->assertEqualsWithDelta(20.0, (float) $correction->logistics, 0.001);
    }

    public function test_process_report_calculates_percent_buy_from_delivery_to_client_types(): void
    {
        $report = $this->processReportWithRows('Buyout Delivery Cabinet', [
            $this->deliveryApiRow('К клиенту при продаже', 111),
            $this->deliveryApiRow('К клиенту при продаже', 111),
            $this->deliveryApiRow('К клиенту при отмене', 111),
            [
                'sellerOperName' => 'Логистика',
                'deliveryService' => 40,
                'forPay' => 0,
                'quantity' => 1,
                'nmId' => 111,
                'vendorCode' => 'SKU-1',
                'sku' => 'barcode-1',
                'officeName' => 'Коледино',
                'bonusTypeName' => 'К клиенту при продаже',
            ],
            $this->deliveryApiRow('От клиента при возврате', 111),
        ]);

        $this->assertEqualsWithDelta(66.67, (float) $report->percent_buy, 0.001);
    }

    public function test_process_report_percent_buy_is_zero_when_only_delivery_cancellations(): void
    {
        $report = $this->processReportWithRows('Buyout Cancel Only Cabinet', [
            $this->deliveryApiRow('К клиенту при отмене', 222),
            $this->deliveryApiRow('К клиенту при отмене', 222),
        ]);

        $this->assertEqualsWithDelta(0.0, (float) $report->percent_buy, 0.001);
    }

    public function test_process_report_percent_buy_is_zero_without_delivery_to_client_types(): void
    {
        $report = $this->processReportWithRows('Buyout No Types Cabinet', [
            $this->deliveryApiRow('До покупателя', 333),
            [
                'sellerOperName' => 'Логистика',
                'deliveryService' => 15,
                'forPay' => 0,
                'quantity' => 1,
                'nmId' => 333,
                'vendorCode' => 'SKU-3',
                'sku' => 'barcode-3',
                'officeName' => 'Коледино',
                'bonusTypeName' => 'К клиенту при продаже',
            ],
        ]);

        $this->assertEqualsWithDelta(0.0, (float) $report->percent_buy, 0.001);
    }

    public function test_workspace_exposes_return_compensation_in_other_group(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Compensation Items Cabinet');

        $report = Report::query()->create([
            'cabinet_id' => $cabinet->id,
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-15',
            'sales_quantity' => 1,
            'sales_amount' => 1000,
            'return_compensation' => 150,
            'itog' => 1150,
            'margin' => 1150,
        ]);

        Item::query()->create([
            'report_id' => $report->id,
            'nm_id' => 333,
            'sa_name' => 'COMP-A',
            'supplier_oper_name' => 'Добровольная компенсация при возврате',
            'quantity' => 1,
            'sum_to_transfer' => 150,
        ]);

        Cache::flush();

        $this->actingAs($user)
            ->get('/panel/wb/profitability')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('groupMeta.other', 1)
                ->where('report.return_compensation', 150));

        $this->actingAs($user)
            ->getJson('/panel/wb/profitability/items?group=other')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.sa_name', 'COMP-A')
            ->assertJsonPath('data.0.type', 'Компенсация при возврате')
            ->assertJsonPath('data.0.sum_to_transfer', 150);
    }

    public function test_process_report_adds_return_compensation_sale_and_subtracts_return(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Compensation Job Cabinet');

        $this->ensurePriceCalcV3Table();

        $api = \Mockery::mock(ProfitabilityApiService::class);
        $api->shouldReceive('getReportDetailByPeriod')
            ->once()
            ->andReturn([
                'success' => true,
                'code' => 200,
                'data' => [
                    [
                        'sellerOperName' => 'Продажа',
                        'forPay' => 1000,
                        'retailAmount' => 1000,
                        'quantity' => 1,
                        'nmId' => 444,
                        'vendorCode' => 'SKU-4',
                        'sku' => 'barcode-4',
                        'officeName' => 'Коледино',
                        'cashbackAmount' => 10,
                        'cashbackDiscount' => 5,
                    ],
                    [
                        'sellerOperName' => 'Добровольная компенсация при возврате',
                        'docTypeName' => 'Продажа',
                        'forPay' => 200,
                        'quantity' => 1,
                        'nmId' => 444,
                        'vendorCode' => 'SKU-4',
                        'sku' => 'barcode-4',
                        'officeName' => 'Коледино',
                    ],
                    [
                        'sellerOperName' => 'Добровольная компенсация при возврате',
                        'docTypeName' => 'Возврат',
                        'forPay' => 50,
                        'quantity' => 1,
                        'nmId' => 444,
                        'vendorCode' => 'SKU-4',
                        'sku' => 'barcode-4',
                        'officeName' => 'Коледино',
                    ],
                    [
                        'sellerOperName' => 'Компенсация скидки по программе лояльности',
                        'forPay' => 999,
                        'nmId' => 444,
                    ],
                    [
                        'sellerOperName' => 'Возмещение за выдачу и возврат товаров на ПВЗ',
                        'forPay' => 888,
                        'nmId' => 444,
                    ],
                    [
                        'sellerOperName' => 'Возмещение издержек по перевозке/по складским операциям с товаром',
                        'forPay' => 777,
                        'nmId' => 444,
                    ],
                    [
                        'sellerOperName' => 'Возмещение издержек по перемещению и операционной обработке товара',
                        'forPay' => 666,
                        'nmId' => 444,
                    ],
                ],
            ]);

        $job = new ProcessProfitabilityReport(
            (int) $cabinet->id,
            '2026-03-01',
            '2026-03-07',
            (int) $user->id
        );
        $job->handle($api);

        $report = Report::query()->where('cabinet_id', $cabinet->id)->first();
        $this->assertNotNull($report);
        $this->assertEqualsWithDelta(150.0, (float) $report->return_compensation, 0.001);
        $this->assertEqualsWithDelta(15.0, (float) $report->cashback, 0.001);
        // 1000 продажи - 15 кэшбэк + 200 компенсация - 50 сторно = 1135
        $this->assertEqualsWithDelta(1135.0, (float) $report->itog, 0.001);

        $compensationRows = Item::query()
            ->where('report_id', $report->id)
            ->where('supplier_oper_name', 'Добровольная компенсация при возврате')
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $compensationRows);
        $this->assertEqualsWithDelta(200.0, (float) $compensationRows[0]->sum_to_transfer, 0.001);
        $this->assertEqualsWithDelta(-50.0, (float) $compensationRows[1]->sum_to_transfer, 0.001);

        $ignoredCount = Item::query()
            ->where('report_id', $report->id)
            ->whereIn('supplier_oper_name', [
                'Компенсация скидки по программе лояльности',
                'Возмещение за выдачу и возврат товаров на ПВЗ',
                'Возмещение издержек по перевозке/по складским операциям с товаром',
                'Возмещение издержек по перемещению и операционной обработке товара',
            ])
            ->count();
        $this->assertSame(0, $ignoredCount);
    }

    public function test_workspace_exposes_returns_correction_in_other_group(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Returns Correction Items Cabinet');

        $report = Report::query()->create([
            'cabinet_id' => $cabinet->id,
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-15',
            'sales_quantity' => 1,
            'sales_amount' => 1000,
            'correction_returns' => 80,
            'itog' => 920,
            'margin' => 920,
        ]);

        Item::query()->create([
            'report_id' => $report->id,
            'nm_id' => 666,
            'sa_name' => 'RET-CORR',
            'supplier_oper_name' => 'Коррекция возвратов',
            'quantity' => 1,
            'sum_to_transfer' => 80,
        ]);

        Cache::flush();

        $this->actingAs($user)
            ->get('/panel/wb/profitability')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('groupMeta.other', 1)
                ->where('report.correction_returns', 80));

        $this->actingAs($user)
            ->getJson('/panel/wb/profitability/items?group=other')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.sa_name', 'RET-CORR')
            ->assertJsonPath('data.0.type', 'Коррекция возвратов')
            ->assertJsonPath('data.0.sum_to_transfer', 80);
    }

    public function test_process_report_subtracts_returns_correction_from_itog(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Returns Correction Job Cabinet');

        $this->ensurePriceCalcV3Table();

        $api = \Mockery::mock(ProfitabilityApiService::class);
        $api->shouldReceive('getReportDetailByPeriod')
            ->once()
            ->andReturn([
                'success' => true,
                'code' => 200,
                'data' => [
                    [
                        'sellerOperName' => 'Продажа',
                        'forPay' => 1000,
                        'retailAmount' => 1000,
                        'quantity' => 1,
                        'nmId' => 666,
                        'vendorCode' => 'SKU-6',
                        'sku' => 'barcode-6',
                        'officeName' => 'Коледино',
                    ],
                    [
                        'sellerOperName' => 'Коррекция возвратов',
                        'forPay' => 80,
                        'quantity' => 1,
                        'nmId' => 666,
                        'vendorCode' => 'SKU-6',
                        'sku' => 'barcode-6',
                        'officeName' => 'Коледино',
                    ],
                ],
            ]);

        $job = new ProcessProfitabilityReport(
            (int) $cabinet->id,
            '2026-05-01',
            '2026-05-07',
            (int) $user->id
        );
        $job->handle($api);

        $report = Report::query()->where('cabinet_id', $cabinet->id)->first();
        $this->assertNotNull($report);
        $this->assertEqualsWithDelta(80.0, (float) $report->correction_returns, 0.001);
        $this->assertEqualsWithDelta(1000.0, (float) $report->sales_amount, 0.001);
        // 1000 продажи − 80 коррекция возвратов
        $this->assertEqualsWithDelta(920.0, (float) $report->itog, 0.001);
        $this->assertEqualsWithDelta(920.0, (float) $report->margin, 0.001);

        $correction = Item::query()
            ->where('report_id', $report->id)
            ->where('supplier_oper_name', 'Коррекция возвратов')
            ->first();
        $this->assertNotNull($correction);
        $this->assertEqualsWithDelta(80.0, (float) $correction->sum_to_transfer, 0.001);
    }

    public function test_cabinet_show_survives_widget_items_without_sales_rows(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'Logistics Only Cabinet');

        $report = Report::query()->create([
            'cabinet_id' => $cabinet->id,
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-15',
            'sales_quantity' => 0,
            'sales_amount' => 0,
            'itog' => -150,
            'margin' => -150,
        ]);

        Item::query()->create([
            'report_id' => $report->id,
            'nm_id' => 999001,
            'sa_name' => 'LOG-ONLY',
            'supplier_oper_name' => 'Логистика',
            'quantity' => 1,
            'sum_to_transfer' => -150,
            'margin' => -150,
            'profitability_percent' => 0,
        ]);

        Cache::flush();

        $this->actingAs($user)
            ->get("/panel/wb/profitability/cabinets/{$cabinet->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Wb/Profitability/Cabinet/Show')
                ->where('cabinet.id', $cabinet->id)
                ->has('report')
                ->where('groupMeta.logistics', 1)
                ->where('groupMeta.sales', 0)
                ->has('widget'));
    }

    public function test_cabinet_export_start_dispatches_job(): void
    {
        Queue::fake();

        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'Export Cabinet');

        $report = Report::query()->create([
            'cabinet_id' => $cabinet->id,
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-15',
            'sales_quantity' => 1,
            'sales_amount' => 1000,
            'itog' => 500,
            'margin' => 500,
            'total_profitability' => 50,
        ]);

        Item::query()->create([
            'report_id' => $report->id,
            'nm_id' => 123456,
            'sa_name' => 'TEST-001',
            'supplier_oper_name' => 'Продажа',
            'quantity' => 1,
            'sum_to_transfer' => 1000,
            'margin' => 500,
            'profitability_percent' => 50,
        ]);

        $this->actingAs($user)
            ->postJson("/panel/wb/profitability/cabinets/{$cabinet->id}/export")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'processing');

        Queue::assertPushed(ExportProfitabilityReportJob::class);
    }

    public function test_cabinet_export_job_builds_file_and_download_works(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'Export Job Cabinet');

        $report = Report::query()->create([
            'cabinet_id' => $cabinet->id,
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-15',
            'sales_quantity' => 1,
            'sales_amount' => 1000,
            'itog' => 500,
            'margin' => 500,
            'total_profitability' => 50,
        ]);

        Item::query()->create([
            'report_id' => $report->id,
            'nm_id' => 123456,
            'sa_name' => 'TEST-001',
            'supplier_oper_name' => 'Продажа',
            'quantity' => 1,
            'sum_to_transfer' => 1000,
            'margin' => 500,
            'profitability_percent' => 50,
        ]);

        Cache::flush();

        $this->actingAs($user)
            ->postJson("/panel/wb/profitability/cabinets/{$cabinet->id}/export")
            ->assertOk()
            ->assertJsonPath('stage', 'queued');

        // Run job synchronously for test
        (new ExportProfitabilityReportJob($cabinet->id, $user->id, $report->id))->handle(
            app(WbProfitabilityReportService::class)
        );

        $expectedPath = "wb/profitability/{$user->id}/{$cabinet->id}/{$report->id}.xlsx";
        $this->assertTrue(
            Storage::disk('private')->exists($expectedPath),
            'Export file must be stored under private/wb/profitability'
        );

        $this->actingAs($user)
            ->getJson("/panel/wb/profitability/cabinets/{$cabinet->id}/export/status")
            ->assertOk()
            ->assertJsonPath('status', 'done')
            ->assertJsonPath('ready', true)
            ->assertJsonPath('stage', 'done');

        $response = $this->actingAs($user)
            ->get("/panel/wb/profitability/cabinets/{$cabinet->id}/export/download");

        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertSame('PK', substr($content, 0, 2), 'Exported file should be a valid XLSX archive');
        $this->assertGreaterThan(1000, strlen($content));
    }

    public function test_cabinet_export_reuses_file_when_report_unchanged(): void
    {
        Queue::fake();

        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'Reuse Export Cabinet');

        $report = Report::query()->create([
            'cabinet_id' => $cabinet->id,
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-15',
            'sales_quantity' => 1,
            'sales_amount' => 1000,
            'itog' => 500,
            'margin' => 500,
            'total_profitability' => 50,
        ]);

        Item::query()->create([
            'report_id' => $report->id,
            'nm_id' => 123456,
            'sa_name' => 'TEST-001',
            'supplier_oper_name' => 'Продажа',
            'quantity' => 1,
            'sum_to_transfer' => 1000,
            'margin' => 500,
            'profitability_percent' => 50,
        ]);

        Cache::flush();

        $this->actingAs($user)
            ->postJson("/panel/wb/profitability/cabinets/{$cabinet->id}/export")
            ->assertOk()
            ->assertJsonPath('status', 'processing');

        (new ExportProfitabilityReportJob($cabinet->id, $user->id, $report->id))->handle(
            app(WbProfitabilityReportService::class)
        );

        Queue::fake();

        $this->actingAs($user)
            ->postJson("/panel/wb/profitability/cabinets/{$cabinet->id}/export")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'done')
            ->assertJsonPath('ready', true);

        Queue::assertNotPushed(ExportProfitabilityReportJob::class);
    }

    public function test_cabinet_export_regenerates_after_report_update(): void
    {
        Queue::fake();

        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'Stale Export Data Cabinet');

        $report = Report::query()->create([
            'cabinet_id' => $cabinet->id,
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-15',
            'sales_quantity' => 1,
            'sales_amount' => 1000,
            'itog' => 500,
            'margin' => 500,
            'total_profitability' => 50,
        ]);

        Item::query()->create([
            'report_id' => $report->id,
            'nm_id' => 123456,
            'sa_name' => 'TEST-001',
            'supplier_oper_name' => 'Продажа',
            'quantity' => 1,
            'sum_to_transfer' => 1000,
            'margin' => 500,
            'profitability_percent' => 50,
        ]);

        Cache::flush();

        $this->actingAs($user)
            ->postJson("/panel/wb/profitability/cabinets/{$cabinet->id}/export")
            ->assertOk();

        (new ExportProfitabilityReportJob($cabinet->id, $user->id, $report->id))->handle(
            app(WbProfitabilityReportService::class)
        );

        $exportState = Cache::get('profitability_export_'.$cabinet->id);
        $this->assertSame('done', $exportState['status'] ?? null);
        $oldPath = $exportState['path'] ?? null;
        $this->assertNotEmpty($oldPath);
        $this->assertTrue(
            Storage::disk('private')->exists($oldPath)
        );

        // Симулируем пересчёт: тот же report_id, новые даты/данные + updated_at
        $report->update([
            'date_from' => '2026-02-01',
            'date_to' => '2026-02-28',
            'sales_quantity' => 5,
            'sales_amount' => 9000,
            'itog' => 4000,
            'margin' => 4000,
            'total_profitability' => 80,
        ]);
        $report->refresh();

        app(WbProfitabilityReportService::class)
            ->invalidateExportCache((int) $cabinet->id);

        $this->assertFalse(
            Storage::disk('private')->exists($oldPath),
            'Старый export-файл должен быть удалён при инвалидации'
        );
        $this->assertNull(Cache::get('profitability_export_'.$cabinet->id));

        // Даже без инвалидации fingerprint не должен reuse'ить файл —
        // восстанавливаем «устаревший» done-state с старым report_updated_at
        $staleUpdatedAt = now()->subDay()->utc()->format('Y-m-d\TH:i:s.u\Z');
        $relativePath = "wb/profitability/{$user->id}/{$cabinet->id}/{$report->id}.xlsx";
        Storage::disk('private')->put($relativePath, 'stale-xlsx-bytes');

        Cache::put('profitability_export_'.$cabinet->id, [
            'status' => 'done',
            'stage' => 'done',
            'path' => $relativePath,
            'filename' => 'stale.xlsx',
            'error' => null,
            'report_id' => $report->id,
            'report_updated_at' => $staleUpdatedAt,
            'truncated' => false,
            'updated_at' => now()->toIso8601String(),
        ], 86400);

        Queue::fake();

        $this->actingAs($user)
            ->postJson("/panel/wb/profitability/cabinets/{$cabinet->id}/export")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'processing')
            ->assertJsonPath('ready', false);

        Queue::assertPushed(ExportProfitabilityReportJob::class);
    }

    public function test_cabinet_export_forbidden_for_foreign_cabinet(): void
    {
        $owner = $this->createSubscriberUser(withPermission: true);
        $intruder = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($owner, 'Foreign Export');

        $this->actingAs($intruder)
            ->postJson("/panel/wb/profitability/cabinets/{$cabinet->id}/export")
            ->assertForbidden();
    }

    public function test_cabinet_export_status_marks_stale_processing_as_failed(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'Stale Export Cabinet');

        Cache::put('profitability_export_'.$cabinet->id, [
            'status' => 'processing',
            'path' => null,
            'filename' => 'test.xlsx',
            'error' => null,
            'report_id' => 1,
            'updated_at' => now()->subHours(2)->toIso8601String(),
        ], 3600);

        $this->actingAs($user)
            ->getJson("/panel/wb/profitability/cabinets/{$cabinet->id}/export/status")
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('ready', false)
            ->assertJson(fn ($json) => $json
                ->whereType('error', 'string')
                ->where('error', fn ($error) => str_contains($error, 'Попробуйте'))
                ->etc());
    }

    public function test_cabinet_show_keeps_group_items_while_job_processing(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'Processing Report Cabinet');

        $report = Report::query()->create([
            'cabinet_id' => $cabinet->id,
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-15',
            'sales_quantity' => 2,
            'sales_amount' => 2000,
            'itog' => 1000,
        ]);

        Item::query()->create([
            'report_id' => $report->id,
            'nm_id' => 654321,
            'sa_name' => 'KEEP-001',
            'supplier_oper_name' => 'Продажа',
            'quantity' => 2,
            'sum_to_transfer' => 2000,
            'margin' => 1000,
            'profitability_percent' => 50,
        ]);

        JobStatus::query()->create([
            'job_name' => ProcessProfitabilityReport::class,
            'data' => [
                'cabinet_id' => $cabinet->id,
                'user_id' => $user->id,
                'stage' => 'fetching',
                'batch' => 1,
                'rows_loaded' => 1000,
                'waiting_for_api' => true,
                'started_at' => now()->toIso8601String(),
            ],
            'status' => 'processing',
            'error' => null,
        ]);

        Cache::flush();

        $this->actingAs($user)
            ->get("/panel/wb/profitability/cabinets/{$cabinet->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('jobStatus.status', 'processing')
                ->where('groupMeta.sales', 1)
                ->has('report'));

        $this->actingAs($user)
            ->getJson("/panel/wb/profitability/cabinets/{$cabinet->id}/items?group=sales")
            ->assertOk()
            ->assertJsonPath('data.0.sa_name', 'KEEP-001');
    }

    public function test_cabinet_show_clears_stale_duplicate_rejection_failure(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'Stale Failure Cabinet');

        JobStatus::query()->create([
            'job_name' => ProcessProfitabilityReport::class,
            'data' => [
                'cabinet_id' => $cabinet->id,
                'user_id' => $user->id,
                'stage' => 'fetching',
                'batch' => 1,
                'rows_loaded' => 0,
                'waiting_for_api' => false,
                'started_at' => now()->subHour()->toIso8601String(),
            ],
            'status' => 'failed',
            'error' => 'Отчёт уже выполняется, повторный запрос отклонён.',
        ]);

        $this->actingAs($user)
            ->get("/panel/wb/profitability/cabinets/{$cabinet->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('jobStatus.status', 'done')
                ->where('jobStatus.error', null));

        $this->assertDatabaseHas('job_statuses', [
            'job_name' => ProcessProfitabilityReport::class,
            'status' => 'done',
            'error' => null,
        ]);
    }

    public function test_cabinet_show_exposes_job_progress_while_processing(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'Progress Cabinet');

        JobStatus::query()->create([
            'job_name' => ProcessProfitabilityReport::class,
            'data' => [
                'cabinet_id' => $cabinet->id,
                'user_id' => $user->id,
                'stage' => 'fetching',
                'batch' => 2,
                'rows_loaded' => 150000,
                'waiting_for_api' => true,
                'started_at' => now()->toIso8601String(),
            ],
            'status' => 'processing',
            'error' => null,
        ]);

        $this->actingAs($user)
            ->get("/panel/wb/profitability/cabinets/{$cabinet->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Wb/Profitability/Cabinet/Show')
                ->where('jobStatus.status', 'processing')
                ->where('jobStatus.stage', 'fetching')
                ->where('jobStatus.batch', 2)
                ->where('jobStatus.rows_loaded', 150000)
                ->where('jobStatus.waiting_for_api', true)
                ->has('jobStatus.started_at')
                ->where('jobStatus.progress_percent', 28)
                ->where('jobStatus.status_label', 'Ждём данные от Wildberries')
                ->where('jobStatus.status_detail', fn ($detail) => is_string($detail) && str_contains($detail, '150 000')));
    }

    public function test_store_report_forbidden_for_foreign_cabinet(): void
    {
        $owner = $this->createSubscriberUser(withPermission: true);
        $intruder = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($owner, 'Foreign Report');

        $this->actingAs($intruder)
            ->post("/panel/wb/profitability/cabinets/{$cabinet->id}/report", [
                'date_from' => '2026-01-01',
                'date_to' => '2026-01-15',
            ])
            ->assertForbidden();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function processReportWithRows(string $cabinetName, array $rows): Report
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, $cabinetName);
        $this->ensurePriceCalcV3Table();

        $api = \Mockery::mock(ProfitabilityApiService::class);
        $api->shouldReceive('getReportDetailByPeriod')
            ->once()
            ->andReturn([
                'success' => true,
                'code' => 200,
                'data' => $rows,
            ]);

        $job = new ProcessProfitabilityReport(
            (int) $cabinet->id,
            '2026-01-01',
            '2026-01-15',
            (int) $user->id
        );
        $job->handle($api);

        $report = Report::query()->where('cabinet_id', $cabinet->id)->first();
        $this->assertNotNull($report);

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    private function deliveryApiRow(string $bonusType, int $nmId): array
    {
        return [
            'sellerOperName' => 'Доставка',
            'deliveryService' => 10,
            'forPay' => 0,
            'quantity' => 1,
            'nmId' => $nmId,
            'vendorCode' => 'SKU-'.$nmId,
            'sku' => 'barcode-'.$nmId,
            'officeName' => 'Коледино',
            'bonusTypeName' => $bonusType,
        ];
    }

    private function createSubscriberUser(bool $withPermission = false): User
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
        ]);

        $user->assignRole('Подписчик');

        if ($withPermission) {
            $user->givePermissionTo('subscriber wb profitability');
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

    private function createCabinet(User $user, string $name): ProfitabilityCabinet
    {
        return ProfitabilityCabinet::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'apikey' => 'test-api-key',
        ]);
    }

    private function ensurePriceCalcV3Table(): void
    {
        if (Schema::hasTable('wb_price_calc_v3_data')) {
            return;
        }

        Schema::create('wb_price_calc_v3_data', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cabinet_id');
            $table->unsignedBigInteger('nm_id')->nullable();
            $table->string('barcode')->nullable();
            $table->decimal('cost_price', 12, 2)->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    private function createUnifiedCabinet(User $user, string $name): WbCabinet
    {
        $cabinet = WbCabinet::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'apikey' => 'test-api-key',
            'api_key_hash' => hash('sha256', 'test-api-key-'.$name),
        ]);

        $user->forceFill(['selected_wb_cabinet_id' => $cabinet->id])->save();

        return $cabinet;
    }

    private function setupProfitabilitySchema(): void
    {
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

        if (! Schema::hasTable('wb_profitability_cabinets')) {
            Schema::create('wb_profitability_cabinets', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('name');
                $table->text('apikey')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('wb_profitability_reports')) {
            Schema::create('wb_profitability_reports', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('cabinet_id')->index();
                $table->date('date_from')->nullable();
                $table->date('date_to')->nullable();
                $table->unsignedInteger('sales_quantity')->default(0);
                $table->decimal('sales_amount', 14, 2)->default(0);
                $table->unsignedInteger('returns_quantity')->default(0);
                $table->decimal('returns_amount', 14, 2)->default(0);
                $table->decimal('percent_buy', 8, 2)->default(0);
                $table->decimal('penalties', 14, 2)->default(0);
                $table->decimal('logistics', 14, 2)->default(0);
                $table->decimal('delivery', 14, 2)->default(0);
                $table->decimal('purchase_cost', 14, 2)->default(0);
                $table->decimal('margin', 14, 2)->default(0);
                $table->decimal('deduction', 14, 2)->default(0);
                $table->decimal('storage_fee', 14, 2)->default(0);
                $table->decimal('acceptance', 14, 2)->default(0);
                $table->decimal('cashback', 14, 2)->default(0);
                $table->decimal('return_compensation', 14, 2)->default(0);
                $table->decimal('dop_rashod', 14, 2)->default(0);
                $table->decimal('nalog', 14, 2)->default(0);
                $table->decimal('nalog_percent', 5, 2)->default(0);
                $table->decimal('correction_sales', 14, 2)->default(0);
                $table->decimal('correction_returns', 14, 2)->default(0);
                $table->decimal('total_profitability', 8, 2)->default(0);
                $table->decimal('itog', 14, 2)->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('wb_profitability_items')) {
            Schema::create('wb_profitability_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('report_id')->index();
                $table->unsignedBigInteger('nm_id')->nullable();
                $table->string('sa_name')->nullable();
                $table->string('supplier_oper_name')->nullable();
                $table->text('reasoning')->nullable();
                $table->string('size')->nullable();
                $table->string('barcode')->nullable();
                $table->string('warehouse')->nullable();
                $table->integer('quantity')->default(0);
                $table->decimal('price_without_spp', 14, 2)->default(0);
                $table->decimal('sum_to_transfer', 14, 2)->default(0);
                $table->decimal('purchase_cost', 14, 2)->default(0);
                $table->decimal('logistics', 14, 2)->default(0);
                $table->decimal('delivery', 14, 2)->default(0);
                $table->decimal('cost_adjustments', 14, 2)->default(0);
                $table->decimal('dop_rashod', 14, 2)->default(0);
                $table->decimal('cashback', 14, 2)->default(0);
                $table->decimal('nalog', 14, 2)->default(0);
                $table->decimal('margin', 14, 2)->default(0);
                $table->decimal('profitability_percent', 8, 2)->default(0);
                $table->timestamps();
            });
        }

        if (Schema::hasTable('wb_profitability_reports') && ! Schema::hasColumn('wb_profitability_reports', 'delivery')) {
            Schema::table('wb_profitability_reports', function (Blueprint $table) {
                $table->decimal('delivery', 14, 2)->default(0);
            });
        }

        if (Schema::hasTable('wb_profitability_items') && ! Schema::hasColumn('wb_profitability_items', 'delivery')) {
            Schema::table('wb_profitability_items', function (Blueprint $table) {
                $table->decimal('delivery', 14, 2)->default(0);
            });
        }

        if (Schema::hasTable('wb_profitability_reports') && ! Schema::hasColumn('wb_profitability_reports', 'return_compensation')) {
            Schema::table('wb_profitability_reports', function (Blueprint $table) {
                $table->decimal('return_compensation', 14, 2)->default(0);
            });
        }

        if (Schema::hasTable('wb_profitability_reports') && ! Schema::hasColumn('wb_profitability_reports', 'correction_returns')) {
            Schema::table('wb_profitability_reports', function (Blueprint $table) {
                $table->decimal('correction_returns', 14, 2)->default(0);
            });
        }

        if (! Schema::hasTable('job_statuses')) {
            Schema::create('job_statuses', function (Blueprint $table) {
                $table->id();
                $table->string('job_name');
                $table->json('data')->nullable();
                $table->string('status')->default('processing');
                $table->text('error')->nullable();
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
