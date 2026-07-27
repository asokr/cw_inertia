<?php

namespace Tests\Feature\Web\Subscriber\Wb;

use App\Models\Subscribers\Subscribers;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\Subscribers\Wb\AbTesting\AbProduct;
use App\Models\Subscribers\Wb\WbCabinet;
use App\Models\User;
use App\Services\Wb\WbPriceCalculationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Web\Auth\WebAuthTestCase;

class WbAbTestingTest extends WebAuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setupAbTestingSchema();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::firstOrCreate([
            'name' => 'subscriber wb ab testing',
            'guard_name' => 'web',
        ]);
    }

    public function test_guest_cannot_access_index(): void
    {
        $this->get('/panel/wb/ab-testing')->assertRedirect('/login');
    }

    public function test_user_without_permission_cannot_access_index(): void
    {
        $user = $this->createSubscriberUser();

        $this->actingAs($user)
            ->get('/panel/wb/ab-testing')
            ->assertForbidden();
    }

    public function test_subscriber_with_permission_sees_no_cabinet_without_unified_cabinet(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);

        $this->actingAs($user)
            ->get('/panel/wb/ab-testing')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Wb/Shared/NoCabinet')
                ->where('toolName', 'A/B-тестирование'));
    }

    public function test_index_renders_workspace_for_selected_unified_cabinet(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Test Cabinet');

        $this->actingAs($user)
            ->get('/panel/wb/ab-testing')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Wb/AbTesting/Index')
                ->where('cabinet.id', $cabinet->id)
                ->where('cabinet.name', 'Test Cabinet')
                ->has('products')
                ->has('productsMeta')
                ->has('filters'));
    }

    public function test_search_filters_products_by_nm_id_and_vendor_code(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Search Cabinet');

        AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 111111,
            'vendor_code' => 'SKU-AAA',
            'title' => 'Product A',
        ]);
        AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 222222,
            'vendor_code' => 'SKU-BBB',
            'title' => 'Product B',
        ]);

        $this->actingAs($user)
            ->get('/panel/wb/ab-testing?search=111111')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Wb/AbTesting/Index')
                ->has('products', 1)
                ->where('products.0.nm_id', 111111)
                ->where('products.0.test_status', 'not_created'));

        $this->actingAs($user)
            ->get('/panel/wb/ab-testing?search=SKU-BBB')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('products', 1)
                ->where('products.0.vendor_code', 'SKU-BBB'));
    }

    public function test_sync_stores_products_with_photo_from_content_api(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Sync Cabinet');

        $mock = Mockery::mock(WbPriceCalculationService::class);
        $mock->shouldReceive('getAllCards')
            ->once()
            ->andReturn([
                'code' => 200,
                'response' => json_encode([
                    'cards' => [
                        [
                            'nmID' => 555001,
                            'vendorCode' => 'VC-1',
                            'title' => 'Тестовый товар',
                            'brand' => 'BrandX',
                            'subjectName' => 'Одежда',
                            'photos' => [
                                [
                                    'c246x328' => 'https://example.test/photo-246.webp',
                                    'big' => 'https://example.test/photo-big.webp',
                                ],
                            ],
                        ],
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ]);
        $mock->shouldReceive('parseApiResponse')
            ->once()
            ->andReturnUsing(function ($resp) {
                return [
                    'success' => true,
                    'code' => 200,
                    'data' => json_decode($resp['response'], true),
                ];
            });

        $this->app->instance(WbPriceCalculationService::class, $mock);

        $this->actingAs($user)
            ->post('/panel/wb/ab-testing/sync')
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('wb_ab_products', [
            'cabinet_id' => $cabinet->id,
            'nm_id' => 555001,
            'vendor_code' => 'VC-1',
            'title' => 'Тестовый товар',
            'brand' => 'BrandX',
            'subject_name' => 'Одежда',
            'photo_url' => 'https://example.test/photo-246.webp',
        ]);
    }

    public function test_sync_returns_error_on_invalid_api_key(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $this->createUnifiedCabinet($user, 'Fail Cabinet');

        $mock = Mockery::mock(WbPriceCalculationService::class);
        $mock->shouldReceive('getAllCards')->once()->andReturn(['code' => 401, 'response' => '{}']);
        $mock->shouldReceive('parseApiResponse')->once()->andReturn([
            'success' => false,
            'code' => 401,
            'data' => 'Unauthorized',
        ]);

        $this->app->instance(WbPriceCalculationService::class, $mock);

        $this->actingAs($user)
            ->from('/panel/wb/ab-testing')
            ->post('/panel/wb/ab-testing/sync')
            ->assertRedirect('/panel/wb/ab-testing')
            ->assertSessionHas('error');
    }

    private function createSubscriberUser(bool $withPermission = false): User
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
        ]);

        $user->assignRole('Подписчик');

        if ($withPermission) {
            $user->givePermissionTo('subscriber wb ab testing');
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

    private function setupAbTestingSchema(): void
    {
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'selected_wb_cabinet_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedBigInteger('selected_wb_cabinet_id')->nullable();
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

        if (! Schema::hasTable('wb_ab_products')) {
            Schema::create('wb_ab_products', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('cabinet_id')->index();
                $table->unsignedBigInteger('nm_id');
                $table->string('vendor_code')->nullable();
                $table->string('title')->nullable();
                $table->string('brand')->nullable();
                $table->string('subject_name')->nullable();
                $table->string('photo_url', 1024)->nullable();
                $table->decimal('price', 12, 2)->nullable();
                $table->decimal('rating', 4, 2)->nullable();
                $table->timestamps();
                $table->unique(['cabinet_id', 'nm_id']);
            });
        }
    }
}
