<?php

namespace Tests\Feature\Web\Subscriber\Oz;

use App\Jobs\Ozon\CalculatePriceJob;
use App\Models\Subscribers\Oz\OzCabinet;
use App\Models\Subscribers\Oz\PriceCalc\OzPriceCalcFbo;
use App\Models\Subscribers\Oz\PriceCalc\OzPriceCalcFbs;
use App\Models\Subscribers\Subscribers;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Web\Auth\WebAuthTestCase;

class OzPriceCalcTest extends WebAuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setupOzPriceCalcSchema();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::firstOrCreate([
            'name' => 'subscriber oz price calc',
            'guard_name' => 'web',
        ]);
    }

    public function test_guest_cannot_access_oz_price_calc_index(): void
    {
        $this->get('/panel/oz/price-calc')->assertRedirect();
    }

    public function test_user_without_permission_cannot_access_index(): void
    {
        $user = $this->createSubscriberUser();

        $this->actingAs($user)
            ->get('/panel/oz/price-calc')
            ->assertForbidden();
    }

    public function test_no_cabinet_shows_placeholder(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);

        $this->actingAs($user)
            ->get('/panel/oz/price-calc')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Oz/Shared/NoCabinet')
                ->where('toolName', 'Ценообразование Ozon'));
    }

    public function test_workspace_renders_for_selected_cabinet_fbo(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'Workspace Cabinet');
        $this->createFboRow($cabinet);

        $this->actingAs($user)
            ->get('/panel/oz/price-calc?mode=fbo')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Oz/PriceCalc/Cabinet/Show')
                ->where('cabinet.id', $cabinet->id)
                ->where('mode', 'fbo')
                ->has('rows')
                ->has('columns')
                ->has('jobStatus')
                ->where('columns', fn ($columns) => $this->columnTitle($columns, 'logistics_fbo') === 'Средний тариф по кластерам (руб)'
                    && $this->columnTitle($columns, 'logistics_fbo_over_190') === 'Средний тариф с учетом % выкупа'));
    }

    public function test_workspace_renders_for_selected_cabinet_fbs(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $this->createCabinet($user, 'FBS Cabinet');

        $this->actingAs($user)
            ->get('/panel/oz/price-calc?mode=fbs')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Oz/PriceCalc/Cabinet/Show')
                ->where('mode', 'fbs')
                ->has('rows')
                ->has('columns')
                ->where('columns', fn ($columns) => $this->columnTitle($columns, 'logistics_fbs') === 'Средний тариф'
                    && $this->columnTitle($columns, 'logistics_fbs_over_190') === 'Средний тариф с учетом % выкупа'));
    }

    public function test_legacy_cabinet_url_redirects_to_flat_workspace(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'Legacy');

        $this->actingAs($user)
            ->get("/panel/oz/price-calc/cabinets/{$cabinet->id}")
            ->assertRedirect('/panel/oz/price-calc');
    }

    public function test_store_cabinet_via_unified_route(): void
    {
        $user = $this->createSubscriberUser(withPermission: true, ozCabinetLimit: 2);

        $this->actingAs($user)
            ->from('/panel')
            ->post('/panel/oz/cabinets', [
                'name' => 'New Cabinet',
                'client_id' => 'client-new',
                'apikey' => 'test-key',
            ])
            ->assertRedirect('/panel')
            ->assertSessionHas('success');

        $this->assertDatabaseHas('oz_cabinets', [
            'user_id' => $user->id,
            'name' => 'New Cabinet',
            'client_id' => 'client-new',
        ]);

        $user->refresh();
        $this->assertNotNull($user->selected_oz_cabinet_id);
    }

    public function test_store_cabinet_rejected_when_limit_exhausted(): void
    {
        $user = $this->createSubscriberUser(withPermission: true, ozCabinetLimit: 0);

        $this->actingAs($user)
            ->from('/panel')
            ->post('/panel/oz/cabinets', [
                'name' => 'Blocked Cabinet',
                'client_id' => 'client-blocked',
                'apikey' => 'test-key',
            ])
            ->assertRedirect('/panel')
            ->assertSessionHasErrors('name');

        $this->assertDatabaseMissing('oz_cabinets', [
            'user_id' => $user->id,
            'name' => 'Blocked Cabinet',
        ]);
    }

    public function test_store_cabinet_decrements_limit(): void
    {
        $user = $this->createSubscriberUser(withPermission: true, ozCabinetLimit: 2);

        $this->actingAs($user)
            ->post('/panel/oz/cabinets', [
                'name' => 'New Cabinet',
                'client_id' => 'client-new',
                'apikey' => 'test-key',
            ])
            ->assertSessionHas('success');

        $subscription = SubscribersSubscriptions::query()
            ->where('subscribers_id', $user->subscriber->id)
            ->first();

        $this->assertSame(1, (int) $subscription->limits_plan['oz_cabinets']);
    }

    public function test_destroy_cabinet_via_unified_route(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'Delete Me');

        $this->actingAs($user)
            ->from('/panel')
            ->delete("/panel/oz/cabinets/{$cabinet->id}")
            ->assertRedirect('/panel')
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('oz_cabinets', ['id' => $cabinet->id]);
    }

    public function test_select_cabinet_redirects_to_panel(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $a = $this->createCabinet($user, 'A');
        $b = $this->createCabinet($user, 'B');
        $user->forceFill(['selected_oz_cabinet_id' => $a->id])->save();

        $this->actingAs($user)
            ->post('/panel/oz/cabinets/select', ['cabinet_id' => $b->id])
            ->assertRedirect(route('subscriber.panel'))
            ->assertSessionHas('success');

        $this->assertSame($b->id, (int) $user->fresh()->selected_oz_cabinet_id);
    }

    public function test_sync_fbo_dispatches_batch(): void
    {
        Bus::fake();

        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'Sync Cabinet');

        $this->actingAs($user)
            ->post('/panel/oz/price-calc/sync')
            ->assertRedirect()
            ->assertSessionHas('success');

        Bus::assertBatched(fn ($batch) => $batch->name === "ozon_fbo_sync_{$cabinet->id}");
    }

    public function test_calculate_fbo_dispatches_batch(): void
    {
        Bus::fake();

        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'Calc Cabinet');

        $this->actingAs($user)
            ->post('/panel/oz/price-calc/calculate')
            ->assertRedirect()
            ->assertSessionHas('success');

        Bus::assertBatched(fn ($batch) => $batch->name === "ozon_fbo_calc_{$cabinet->id}");
    }

    public function test_calculate_fbo_uses_cluster_average_tariff(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'FBO Calc');

        $row = OzPriceCalcFbo::query()->create([
            'cabinet_id' => $cabinet->id,
            'ozon_article' => 'ART-FBO-1L',
            'barcode' => '2000000000002',
            'cost_price' => 100,
            'margin_percent' => 20,
            'fulfillment_fee' => 10,
            'dop_rashod_percent' => 5,
            'weight_kg' => 0.25,
            'length_cm' => 10,
            'width_cm' => 10,
            'height_cm' => 10,
            'buyout_percent' => 80,
            'price_markup_for_logistics_percent' => 0,
            'dopakovka_rub' => 0,
            'tax_percent' => 6,
            'commission_percent' => 15,
            'advertising_percent' => 5,
            'promotion_percent' => 10,
        ]);

        (new CalculatePriceJob($cabinet->id, 'fbo'))->handle();

        $row->refresh();

        $this->assertSame(1, (int) $row->volume_liters);
        $this->assertEqualsWithDelta(88.54, (float) $row->logistics_fbo, 0.001);
        $this->assertSame(133, (int) $row->logistics_fbo_over_190);
        $this->assertNotNull($row->min_price);
    }

    public function test_calculate_fbs_uses_cluster_average_tariff_and_min_price_plus_55(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'FBS Calc');

        $row = OzPriceCalcFbs::query()->create([
            'cabinet_id' => $cabinet->id,
            'ozon_article' => 'ART-FBS-1L',
            'barcode' => '2000000000003',
            'cost_price' => 100,
            'margin_percent' => 20,
            'fulfillment_fee' => 10,
            'dop_rashod_percent' => 5,
            'weight_kg' => 0.25,
            'length_cm' => 10,
            'width_cm' => 10,
            'height_cm' => 10,
            'buyout_percent' => 80,
            'tax_percent' => 6,
            'commission_percent' => 15,
            'advertising_percent' => 5,
            'promotion_percent' => 10,
        ]);

        (new CalculatePriceJob($cabinet->id, 'fbs'))->handle();

        $row->refresh();

        $this->assertSame(1, (int) $row->volume_liters);
        $this->assertEqualsWithDelta(80.0, (float) $row->buyout_percent, 0.001);
        $this->assertEqualsWithDelta(88.54, (float) $row->logistics_fbs, 0.001);
        $this->assertSame(133, (int) $row->logistics_fbs_over_190);
        $this->assertSame(449, (int) $row->min_price);
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $columns
     */
    private function columnTitle(iterable $columns, string $key): ?string
    {
        foreach ($columns as $column) {
            if (($column['key'] ?? null) === $key) {
                return $column['title'] ?? null;
            }
        }

        return null;
    }

    private function createSubscriberUser(bool $withPermission = false, ?int $ozCabinetLimit = null): User
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
        ]);

        $user->assignRole('Подписчик');

        if ($withPermission) {
            $user->givePermissionTo('subscriber oz price calc');
        }

        $subscriber = Subscribers::query()->create([
            'user_id' => $user->id,
            'status' => 1,
        ]);

        if ($ozCabinetLimit !== null) {
            SubscribersSubscriptions::query()->create([
                'subscribers_id' => $subscriber->id,
                'plan_id' => 1,
                'status' => 1,
                'end_date' => now()->addMonth(),
                // legacy key still used on many plans; service falls back to it
                'limits_plan' => ['oz_cabinets' => $ozCabinetLimit],
            ]);
        }

        return $user;
    }

    private function createCabinet(User $user, string $name): OzCabinet
    {
        $cabinet = OzCabinet::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'client_id' => 'client-'.uniqid(),
            'apikey' => 'test-api-key',
        ]);

        if (! $user->selected_oz_cabinet_id) {
            $user->forceFill(['selected_oz_cabinet_id' => $cabinet->id])->save();
        }

        return $cabinet;
    }

    private function createFboRow(OzCabinet $cabinet): OzPriceCalcFbo
    {
        return OzPriceCalcFbo::query()->create([
            'cabinet_id' => $cabinet->id,
            'ozon_article' => 'ART-001',
            'barcode' => '2000000000001',
            'cost_price' => 100,
            'margin_percent' => 20,
        ]);
    }

    private function setupOzPriceCalcSchema(): void
    {
        if (! Schema::hasTable('oz_cabinets')) {
            Schema::create('oz_cabinets', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('name');
                $table->string('client_id');
                $table->text('apikey')->nullable();
                $table->text('last_sync_error')->nullable();
                $table->timestamps();
                $table->unique(['user_id', 'client_id']);
            });
        }

        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'selected_oz_cabinet_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedBigInteger('selected_oz_cabinet_id')->nullable();
            });
        }

        if (! Schema::hasTable('oz_price_calc_fbo')) {
            Schema::create('oz_price_calc_fbo', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('cabinet_id')->index();
                $table->string('ozon_article')->nullable();
                $table->string('barcode')->nullable();
                $table->decimal('cost_price', 12, 2)->nullable();
                $table->decimal('margin_percent', 6, 2)->nullable();
                $table->decimal('fulfillment_fee', 12, 2)->nullable();
                $table->decimal('dop_rashod_percent', 6, 2)->nullable();
                $table->decimal('stop_price', 12, 2)->nullable();
                $table->decimal('weight_kg', 10, 3)->nullable();
                $table->decimal('length_cm', 10, 2)->nullable();
                $table->decimal('width_cm', 10, 2)->nullable();
                $table->decimal('height_cm', 10, 2)->nullable();
                $table->decimal('volume_liters', 10, 3)->nullable();
                $table->decimal('logistics_markup_percent', 6, 2)->nullable();
                $table->decimal('buyout_percent', 6, 2)->nullable();
                $table->decimal('logistics_fbo', 12, 2)->nullable();
                $table->decimal('logistics_fbo_over_190', 12, 2)->nullable();
                $table->decimal('acceptance_fbo', 12, 2)->nullable();
                $table->decimal('price_markup_for_logistics_percent', 6, 2)->nullable();
                $table->decimal('dopakovka_rub', 12, 2)->nullable();
                $table->decimal('tax_percent', 6, 2)->nullable();
                $table->decimal('commission_percent', 6, 2)->nullable();
                $table->decimal('advertising_percent', 6, 2)->nullable();
                $table->decimal('promotion_percent', 6, 2)->nullable();
                $table->decimal('min_price', 12, 2)->nullable();
                $table->decimal('current_price', 12, 2)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('oz_price_calc_fbs')) {
            Schema::create('oz_price_calc_fbs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('cabinet_id')->index();
                $table->string('ozon_article')->nullable();
                $table->string('barcode')->nullable();
                $table->decimal('cost_price', 12, 2)->nullable();
                $table->decimal('margin_percent', 6, 2)->nullable();
                $table->decimal('fulfillment_fee', 12, 2)->nullable();
                $table->decimal('dop_rashod_percent', 6, 2)->nullable();
                $table->decimal('stop_price', 12, 2)->nullable();
                $table->decimal('weight_kg', 10, 3)->nullable();
                $table->decimal('length_cm', 10, 2)->nullable();
                $table->decimal('width_cm', 10, 2)->nullable();
                $table->decimal('height_cm', 10, 2)->nullable();
                $table->decimal('volume_liters', 10, 3)->nullable();
                $table->decimal('buyout_percent', 6, 2)->nullable();
                $table->decimal('logistics_fbs', 12, 2)->nullable();
                $table->decimal('logistics_fbs_over_190', 12, 2)->nullable();
                $table->decimal('tax_percent', 6, 2)->nullable();
                $table->decimal('commission_percent', 6, 2)->nullable();
                $table->decimal('advertising_percent', 6, 2)->nullable();
                $table->decimal('promotion_percent', 6, 2)->nullable();
                $table->decimal('min_price', 12, 2)->nullable();
                $table->decimal('current_price', 12, 2)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('job_batches')) {
            Schema::create('job_batches', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('name');
                $table->integer('total_jobs');
                $table->integer('pending_jobs');
                $table->integer('failed_jobs');
                $table->longText('failed_job_ids');
                $table->mediumText('options')->nullable();
                $table->integer('cancelled_at')->nullable();
                $table->integer('created_at');
                $table->integer('finished_at')->nullable();
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
