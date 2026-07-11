<?php

namespace Tests\Feature\Web\Subscriber\Oz;

use App\Models\Subscribers\Oz\PriceCalc\OzPriceCalcCabinet;
use App\Models\Subscribers\Oz\PriceCalc\OzPriceCalcFbo;
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
        $this->get('/panel/oz/price-calc')->assertUnauthorized();
    }

    public function test_user_without_permission_cannot_access_index(): void
    {
        $user = $this->createSubscriberUser();

        $this->actingAs($user)
            ->get('/panel/oz/price-calc')
            ->assertForbidden();
    }

    public function test_subscriber_with_permission_can_access_index(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);

        $this->actingAs($user)
            ->get('/panel/oz/price-calc')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Oz/PriceCalc/Index')
                ->has('cabinets'));
    }

    public function test_index_lists_owned_cabinets(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'Test Cabinet');

        $this->actingAs($user)
            ->get('/panel/oz/price-calc')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('cabinets.0.name', 'Test Cabinet')
                ->where('cabinets.0.id', $cabinet->id));
    }

    public function test_cabinet_show_renders_for_owner_fbo(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'Workspace Cabinet');
        $this->createFboRow($cabinet);

        $this->actingAs($user)
            ->get("/panel/oz/price-calc/cabinets/{$cabinet->id}?mode=fbo")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Oz/PriceCalc/Cabinet/Show')
                ->where('cabinet.id', $cabinet->id)
                ->where('mode', 'fbo')
                ->has('rows')
                ->has('columns')
                ->has('jobStatus'));
    }

    public function test_cabinet_show_renders_for_owner_fbs(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'FBS Cabinet');

        $this->actingAs($user)
            ->get("/panel/oz/price-calc/cabinets/{$cabinet->id}?mode=fbs")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Oz/PriceCalc/Cabinet/Show')
                ->where('mode', 'fbs')
                ->has('rows')
                ->has('columns'));
    }

    public function test_cabinet_show_forbidden_for_foreign_cabinet(): void
    {
        $owner = $this->createSubscriberUser(withPermission: true);
        $intruder = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($owner, 'Foreign');

        $this->actingAs($intruder)
            ->get("/panel/oz/price-calc/cabinets/{$cabinet->id}")
            ->assertForbidden();
    }

    public function test_destroy_cabinet_redirects_with_success(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'Delete Me');

        $this->actingAs($user)
            ->delete("/panel/oz/price-calc/cabinets/{$cabinet->id}")
            ->assertRedirect('/panel/oz/price-calc')
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('oz_price_calc_cabinets', ['id' => $cabinet->id]);
    }

    public function test_index_shows_oz_price_calc_limit(): void
    {
        $user = $this->createSubscriberUser(withPermission: true, ozPriceCalcLimit: 3);

        $this->actingAs($user)
            ->get('/panel/oz/price-calc')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('limits.oz_price_calc_clients', 3));
    }

    public function test_store_cabinet_rejected_when_limit_exhausted(): void
    {
        $user = $this->createSubscriberUser(withPermission: true, ozPriceCalcLimit: 0);

        $this->actingAs($user)
            ->post('/panel/oz/price-calc/cabinets', [
                'name' => 'Blocked Cabinet',
                'client_id' => 'client-blocked',
                'apikey' => 'test-key',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('oz_price_calc_cabinets', [
            'user_id' => $user->id,
            'name' => 'Blocked Cabinet',
        ]);
    }

    public function test_store_cabinet_decrements_limit(): void
    {
        $user = $this->createSubscriberUser(withPermission: true, ozPriceCalcLimit: 2);

        $this->actingAs($user)
            ->post('/panel/oz/price-calc/cabinets', [
                'name' => 'New Cabinet',
                'client_id' => 'client-new',
                'apikey' => 'test-key',
            ])
            ->assertRedirect('/panel/oz/price-calc')
            ->assertSessionHas('success');

        $subscription = SubscribersSubscriptions::query()
            ->where('subscribers_id', $user->subscriber->id)
            ->first();

        $this->assertSame(1, (int) $subscription->limits_plan['oz_price_calc_clients']);
    }

    public function test_destroy_cabinet_increments_limit(): void
    {
        $user = $this->createSubscriberUser(withPermission: true, ozPriceCalcLimit: 0);
        $cabinet = $this->createCabinet($user, 'Restore Limit');

        $this->actingAs($user)
            ->delete("/panel/oz/price-calc/cabinets/{$cabinet->id}")
            ->assertRedirect('/panel/oz/price-calc');

        $subscription = SubscribersSubscriptions::query()
            ->where('subscribers_id', $user->subscriber->id)
            ->first();

        $this->assertSame(1, (int) $subscription->limits_plan['oz_price_calc_clients']);
    }

    public function test_sync_fbo_dispatches_batch(): void
    {
        Bus::fake();

        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'Sync Cabinet');

        $this->actingAs($user)
            ->post("/panel/oz/price-calc/cabinets/{$cabinet->id}/sync")
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
            ->post("/panel/oz/price-calc/cabinets/{$cabinet->id}/calculate")
            ->assertRedirect()
            ->assertSessionHas('success');

        Bus::assertBatched(fn ($batch) => $batch->name === "ozon_fbo_calc_{$cabinet->id}");
    }

    private function createSubscriberUser(bool $withPermission = false, ?int $ozPriceCalcLimit = null): User
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

        if ($ozPriceCalcLimit !== null) {
            SubscribersSubscriptions::query()->create([
                'subscribers_id' => $subscriber->id,
                'plan_id' => 1,
                'status' => 1,
                'end_date' => now()->addMonth(),
                'limits_plan' => ['oz_price_calc_clients' => $ozPriceCalcLimit],
            ]);
        }

        return $user;
    }

    private function createCabinet(User $user, string $name): OzPriceCalcCabinet
    {
        return OzPriceCalcCabinet::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'client_id' => 'client-'.uniqid(),
            'apikey' => 'test-api-key',
        ]);
    }

    private function createFboRow(OzPriceCalcCabinet $cabinet): OzPriceCalcFbo
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
        if (! Schema::hasTable('oz_price_calc_cabinets')) {
            Schema::create('oz_price_calc_cabinets', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('name');
                $table->string('client_id');
                $table->text('apikey')->nullable();
                $table->text('last_sync_error')->nullable();
                $table->timestamps();
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