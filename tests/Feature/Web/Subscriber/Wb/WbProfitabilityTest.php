<?php

namespace Tests\Feature\Web\Subscriber\Wb;

use App\Jobs\ProcessProfitabilityReport;
use App\Models\JobStatus;
use App\Models\Subscribers\Subscribers;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\Subscribers\Wb\Profitability\Item;
use App\Models\Subscribers\Wb\Profitability\ProfitabilityCabinet;
use App\Models\Subscribers\Wb\Profitability\Report;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
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

    public function test_subscriber_with_permission_can_access_index(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);

        $this->actingAs($user)
            ->get('/panel/wb/profitability')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Wb/Profitability/Index')
                ->has('cabinets'));
    }

    public function test_index_lists_owned_cabinets(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'Test Cabinet');

        $this->actingAs($user)
            ->get('/panel/wb/profitability')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('cabinets.0.name', 'Test Cabinet')
                ->where('cabinets.0.id', $cabinet->id));
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
                ->has('groups'));
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
    }

    public function test_cabinet_show_returns_groups_as_list_when_report_exists(): void
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

        $response = $this->actingAs($user)
            ->get("/panel/wb/profitability/cabinets/{$cabinet->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Wb/Profitability/Cabinet/Show')
                ->has('groups', 2)
                ->where('groups.0.supplier_oper_name', 'Продажа')
                ->where('groups.1.supplier_oper_name', 'Логистика')
                ->has('groups.0.items', 1)
                ->has('report'));

        $groups = $response->original->getData()['page']['props']['groups'] ?? [];
        $this->assertTrue(array_is_list($groups));
        $this->assertTrue(array_is_list($groups[0]['items']));
        $this->assertSame('TEST-001', $groups[0]['items'][0]['sa_name']);
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
                ->has('groups', 1)
                ->has('groups.0.items', 1)
                ->where('groups.0.items.0.sa_name', 'KEEP-001')
                ->has('report'));
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
                ->has('jobStatus.started_at'));
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

    private function setupProfitabilitySchema(): void
    {
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
                $table->decimal('purchase_cost', 14, 2)->default(0);
                $table->decimal('margin', 14, 2)->default(0);
                $table->decimal('deduction', 14, 2)->default(0);
                $table->decimal('storage_fee', 14, 2)->default(0);
                $table->decimal('acceptance', 14, 2)->default(0);
                $table->decimal('cashback', 14, 2)->default(0);
                $table->decimal('dop_rashod', 14, 2)->default(0);
                $table->decimal('nalog', 14, 2)->default(0);
                $table->decimal('nalog_percent', 5, 2)->default(0);
                $table->decimal('correction_sales', 14, 2)->default(0);
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
                $table->decimal('cost_adjustments', 14, 2)->default(0);
                $table->decimal('dop_rashod', 14, 2)->default(0);
                $table->decimal('cashback', 14, 2)->default(0);
                $table->decimal('nalog', 14, 2)->default(0);
                $table->decimal('margin', 14, 2)->default(0);
                $table->decimal('profitability_percent', 8, 2)->default(0);
                $table->timestamps();
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