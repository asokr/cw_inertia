<?php

namespace Tests\Feature\Wb\Repricer;

use App\Models\Subscribers\Wb\Repricer\RepricerCabinets;
use App\Services\Wb\WbSearchService;
use App\Models\Subscribers\Wb\Repricer\RepricerCompetitor;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Passport;
use Mockery;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RepricerCompetitorsControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setupDatabaseSchema();
        $this->mockWbSearchService();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_user_can_list_own_competitors(): void
    {
        [$user, $cabinet] = $this->createAuthorizedUserWithCabinet();

        $ownCompetitor = RepricerCompetitor::create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 1111,
            'product_data' => ['title' => 'Own'],
            'competitors' => [['nm_id' => 2222]],
            'difference' => 5.5,
            'difference_type' => 'percent',
            'competitors_price_type' => 'min',
            'status' => true,
        ]);

        $otherUser = User::factory()->create();
        $otherCabinet = RepricerCabinets::create([
            'user_id' => $otherUser->id,
            'name' => 'Other cabinet',
            'apikey' => 'key-other',
        ]);
        RepricerCompetitor::create([
            'cabinet_id' => $otherCabinet->id,
            'nm_id' => 3333,
            'product_data' => ['title' => 'Other'],
            'competitors' => [['nm_id' => 4444]],
            'difference' => 2.0,
            'difference_type' => 'amount',
            'competitors_price_type' => 'max',
            'status' => false,
        ]);

        Passport::actingAs($user, [], 'api');

        $response = $this->getJson('/api/subscriber/wb/repricer/competitors');

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertCount(1, $response->json('data'));
        $this->assertEquals($ownCompetitor->id, $response->json('data.0.id'));
    }

    public function test_user_can_store_competitor(): void
    {
        [$user, $cabinet] = $this->createAuthorizedUserWithCabinet();

        Passport::actingAs($user, [], 'api');

        $payload = [
            'cabinet_id' => $cabinet->id,
            'nm_id' => 5555,
            'competitors' => [['nm_id' => 6666]],
            'difference' => 3.4,
            'difference_type' => 'percent',
            'competitors_price_type' => 'average',
            'status' => true,
            'product_data' => [
                'product' => [
                    'supplier' => 'Frontend Supplier',
                    'name' => 'Frontend Product',
                    'price' => 12345,
                ],
                'competitors' => [
                    [
                        'nm_id' => 222222,
                        'price' => 54321,
                    ],
                ],
            ],
        ];

        $response = $this->postJson('/api/subscriber/wb/repricer/competitors', $payload);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'messages' => ['Данные по конкурентам добавлены'],
            ])
            ->assertJsonPath('data.nm_id', 5555)
            ->assertJsonPath('data.status', true)
            ->assertJsonPath('data.product_data.product.supplier', 'Frontend Supplier')
            ->assertJsonPath('data.product_data.product.name', 'Frontend Product')
            ->assertJsonPath('data.product_data.product.price', 12345)
            ->assertJsonPath('data.product_data.competitors.0.nm_id', 222222)
            ->assertJsonPath('data.product_data.competitors.0.price', 54321);

        $this->assertDatabaseHas('wb_repricer_competitors', [
            'cabinet_id' => $cabinet->id,
            'nm_id' => 5555,
            'status' => 1,
        ]);

        $stored = RepricerCompetitor::firstWhere('nm_id', 5555);
        $this->assertSame('Frontend Supplier', $stored->product_data['product']['supplier']);
        $this->assertSame('Frontend Product', $stored->product_data['product']['name']);
        $this->assertSame(12345, $stored->product_data['product']['price']);
        $this->assertSame(222222, $stored->product_data['competitors'][0]['nm_id']);
        $this->assertSame(54321, $stored->product_data['competitors'][0]['price']);
        $this->assertTrue($stored->status);
    }

    public function test_user_can_view_competitor(): void
    {
        [$user, $cabinet] = $this->createAuthorizedUserWithCabinet();

        $competitor = RepricerCompetitor::create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 7777,
            'product_data' => ['title' => 'Single competitor'],
            'competitors' => [['nm_id' => 8888]],
            'difference' => 1.1,
            'difference_type' => 'amount',
            'competitors_price_type' => 'min',
            'status' => true,
        ]);

        Passport::actingAs($user, [], 'api');

        $response = $this->getJson("/api/subscriber/wb/repricer/competitors/{$competitor->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'messages' => ['Информация по конкуренту'],
            ])
            ->assertJsonPath('data.id', $competitor->id);
    }

    public function test_user_can_update_competitor(): void
    {
        [$user, $cabinet] = $this->createAuthorizedUserWithCabinet();

        $competitor = RepricerCompetitor::create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 9999,
            'product_data' => ['title' => 'Old title'],
            'competitors' => [['nm_id' => 1234]],
            'difference' => 2.2,
            'difference_type' => 'percent',
            'competitors_price_type' => 'min',
            'status' => true,
        ]);

        Passport::actingAs($user, [], 'api');

        $payload = [
            'nm_id' => 10000,
            'difference_type' => 'amount',
            'difference' => 4.4,
            'competitors_price_type' => 'max',
            'competitors' => [['nm_id' => 5678]],
            'status' => false,
        ];

        $response = $this->putJson("/api/subscriber/wb/repricer/competitors/{$competitor->id}", $payload);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'messages' => ['Данные обновлены'],
            ])
            ->assertJsonPath('data.nm_id', 10000)
            ->assertJsonPath('data.difference_type', 'amount')
            ->assertJsonPath('data.product_data.price', 53000)
            ->assertJsonPath('data.status', false);

        $this->assertDatabaseHas('wb_repricer_competitors', [
            'id' => $competitor->id,
            'nm_id' => 10000,
            'difference_type' => 'amount',
            'status' => 0,
        ]);
    }

    public function test_user_can_delete_competitor(): void
    {
        [$user, $cabinet] = $this->createAuthorizedUserWithCabinet();

        $competitor = RepricerCompetitor::create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 4321,
            'product_data' => ['title' => 'Delete me'],
            'competitors' => [['nm_id' => 8765]],
            'difference' => 3.3,
            'difference_type' => 'percent',
            'competitors_price_type' => 'average',
            'status' => false,
        ]);

        Passport::actingAs($user, [], 'api');

        $response = $this->deleteJson("/api/subscriber/wb/repricer/competitors/{$competitor->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'messages' => ['Данные удалены'],
            ]);

        $this->assertDatabaseMissing('wb_repricer_competitors', [
            'id' => $competitor->id,
        ]);
    }

    public function test_user_can_toggle_status(): void
    {
        [$user, $cabinet] = $this->createAuthorizedUserWithCabinet();

        $competitor = RepricerCompetitor::create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 1212,
            'status' => false,
        ]);

        Passport::actingAs($user, [], 'api');

        $response = $this->patchJson("/api/subscriber/wb/repricer/competitors/{$competitor->id}/status", [
            'status' => true,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', true);

        $this->assertDatabaseHas('wb_repricer_competitors', [
            'id' => $competitor->id,
            'status' => 1,
        ]);
    }

    public function test_user_cannot_access_foreign_competitor(): void
    {
        [$user, $ownCabinet] = $this->createAuthorizedUserWithCabinet();

        $foreignUser = User::factory()->create();
        $foreignCabinet = RepricerCabinets::create([
            'user_id' => $foreignUser->id,
            'name' => 'Foreign cabinet',
            'apikey' => 'foreign-key',
        ]);
        $foreignCompetitor = RepricerCompetitor::create([
            'cabinet_id' => $foreignCabinet->id,
            'nm_id' => 6543,
            'product_data' => ['title' => 'Foreign'],
            'competitors' => [['nm_id' => 7654]],
            'difference' => 6.6,
            'difference_type' => 'amount',
            'competitors_price_type' => 'min',
            'status' => true,
        ]);

        Passport::actingAs($user, [], 'api');

        $showResponse = $this->getJson("/api/subscriber/wb/repricer/competitors/{$foreignCompetitor->id}");
        $showResponse->assertStatus(200)
            ->assertJson([
                'success' => false,
                'messages' => ['Данных нет'],
            ]);

        $deleteResponse = $this->deleteJson("/api/subscriber/wb/repricer/competitors/{$foreignCompetitor->id}");
        $deleteResponse->assertStatus(200)
            ->assertJson([
                'success' => false,
                'messages' => ['Данных нет'],
            ]);

        $this->assertDatabaseHas('wb_repricer_competitors', [
            'id' => $foreignCompetitor->id,
        ]);
    }

    public function test_user_can_search_competitors_catalog(): void
    {
        [$user] = $this->createAuthorizedUserWithCabinet();

        Passport::actingAs($user, [], 'api');

        $response = $this->getJson('/api/subscriber/wb/repricer/competitors/search?query=boots');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'pending');

        $this->assertNotNull($response->json('data.request_id'));
    }

    public function test_user_can_search_competitors_product_redirect(): void
    {
        [$user] = $this->createAuthorizedUserWithCabinet();

        Passport::actingAs($user, [], 'api');

        $response = $this->getJson('/api/subscriber/wb/repricer/competitors/search?query=redirect-query');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'pending');

        $this->assertNotNull($response->json('data.request_id'));
    }

    public function test_user_can_fetch_bulk_competitors_info(): void
    {
        [$user] = $this->createAuthorizedUserWithCabinet();

        Passport::actingAs($user, [], 'api');

        $response = $this->postJson('/api/subscriber/wb/repricer/competitors/info', [
            'ids' => [987654, 7654321, 987654],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $data = $response->json('data');

        $this->assertCount(2, $data);
        $this->assertSame(987654, $data[0]['nm_id']);
        $this->assertSame(7654321, $data[1]['nm_id']);
        $this->assertSame([], $response->json('failed'));
    }

    private function mockWbSearchService(): void
    {
        $serviceMock = Mockery::mock(WbSearchService::class);

        $serviceMock->shouldReceive('dispatchSearch')
            ->andReturn(true);

        $serviceMock->shouldReceive('product')
            ->andReturnUsing(fn (?int $nmId) => $this->fakeProduct($nmId));

        app()->instance(WbSearchService::class, $serviceMock);
    }

    private function fakeProduct(?int $nmId = null): array
    {
        $productId = $nmId ?? 165584521;
        $priceProduct = match ($productId) {
            987654 => 4300000,
            7654321 => 5300000,
            default => 5300000,
        };

        return [
            'id' => $productId,
            'supplier' => $productId === 987654 ? 'Competitor Supplier' : 'Test Supplier',
            'name' => $productId === 987654 ? 'Competitor Product' : 'Test Product',
            'pics' => 0,
            'reviewRating' => 4.6,
            'nmFeedbacks' => 42,
            'sizes' => [
                [
                    'price' => [
                        'product' => $priceProduct,
                        'basic' => $priceProduct + 1000000,
                    ],
                ],
            ],
        ];
    }

    private function createAuthorizedUserWithCabinet(): array
    {
        $user = User::factory()->create();

        $role = Role::firstOrCreate([
            'name' => 'Подписчик',
            'guard_name' => 'web',
        ]);

        $permission = Permission::firstOrCreate([
            'name' => 'subscriber wb repricer',
            'guard_name' => 'web',
        ]);

        $role->givePermissionTo($permission);
        $user->assignRole($role);
        $user->givePermissionTo($permission);

        $cabinet = RepricerCabinets::create([
            'user_id' => $user->id,
            'name' => 'Test cabinet',
            'apikey' => 'test-key',
        ]);

        return [$user, $cabinet];
    }

    private function setupDatabaseSchema(): void
    {
        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('name')->nullable();
                $table->string('surname')->default('');
                $table->string('email')->unique();
                $table->timestamp('email_verified_at')->nullable();
                $table->string('password');
                $table->rememberToken();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('wb_repricer_cabinets')) {
            Schema::create('wb_repricer_cabinets', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('name');
                $table->text('apikey')->nullable();
                $table->timestamps();

                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            });
        }

        if (!Schema::hasTable('wb_repricer_competitors')) {
            Schema::create('wb_repricer_competitors', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('cabinet_id')->index();
                $table->unsignedBigInteger('nm_id');
                $table->json('product_data')->nullable();
                $table->json('competitors')->nullable();
                $table->double('difference')->nullable();
                $table->string('difference_type')->nullable();
                $table->string('competitors_price_type')->nullable();
                $table->boolean('active')->default(true);
                $table->boolean('status')->default(true);
                $table->double('base_value')->nullable();
                $table->double('base_discount')->nullable();
                $table->unsignedInteger('repeats_counter')->default(0);
                $table->timestamps();

                $table->foreign('cabinet_id')->references('id')->on('wb_repricer_cabinets')->onDelete('cascade');
            });
        }

        if (!Schema::hasTable('wb_search_requests')) {
            Schema::create('wb_search_requests', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('type')->nullable();
                $table->json('payload')->nullable();
                $table->json('data')->nullable();
                $table->string('status')->default('pending');
                $table->text('error')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('permissions')) {
            Schema::create('permissions', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('model_has_permissions')) {
            Schema::create('model_has_permissions', function (Blueprint $table) {
                $table->unsignedBigInteger('permission_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->index(['model_id', 'model_type']);
                $table->primary(['permission_id', 'model_id', 'model_type'], 'model_has_permissions_permission_model_type_primary');

                $table->foreign('permission_id')->references('id')->on('permissions')->onDelete('cascade');
            });
        }

        if (!Schema::hasTable('model_has_roles')) {
            Schema::create('model_has_roles', function (Blueprint $table) {
                $table->unsignedBigInteger('role_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->index(['model_id', 'model_type']);
                $table->primary(['role_id', 'model_id', 'model_type'], 'model_has_roles_role_model_type_primary');

                $table->foreign('role_id')->references('id')->on('roles')->onDelete('cascade');
            });
        }

        if (!Schema::hasTable('role_has_permissions')) {
            Schema::create('role_has_permissions', function (Blueprint $table) {
                $table->unsignedBigInteger('permission_id');
                $table->unsignedBigInteger('role_id');
                $table->primary(['permission_id', 'role_id'], 'role_has_permissions_permission_role_primary');

                $table->foreign('permission_id')->references('id')->on('permissions')->onDelete('cascade');
                $table->foreign('role_id')->references('id')->on('roles')->onDelete('cascade');
            });
        }
    }
}
