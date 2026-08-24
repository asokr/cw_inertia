<?php

namespace Tests\Feature\Web\Subscriber\Wb;

use App\Enums\WbAbTestStatus;
use App\Models\Subscribers\Subscribers;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\Subscribers\Wb\AbTesting\AbCampaign;
use App\Models\Subscribers\Wb\AbTesting\AbExperiment;
use App\Models\Subscribers\Wb\AbTesting\AbExperimentPhoto;
use App\Models\Subscribers\Wb\AbTesting\AbProduct;
use App\Models\Subscribers\Wb\WbCabinet;
use App\Models\User;
use App\Services\Subscriber\Wb\WbAbTestingService;
use App\Services\Wb\WbAdvertApiClient;
use App\Services\Wb\WbPriceCalculationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
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

    public function test_products_with_active_work_are_listed_first(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Sort Cabinet');

        $idle = AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 100001,
            'vendor_code' => 'IDLE',
            'title' => 'No experiment',
        ]);
        $completed = AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 100002,
            'vendor_code' => 'DONE',
            'title' => 'Completed product',
        ]);
        $draft = AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 100003,
            'vendor_code' => 'DRAFT',
            'title' => 'Draft product',
        ]);
        $running = AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 100004,
            'vendor_code' => 'RUN',
            'title' => 'Running product',
        ]);

        AbExperiment::query()->create([
            'ab_product_id' => $completed->id,
            'cabinet_id' => $cabinet->id,
            'name' => 'Done exp',
            'status' => WbAbTestStatus::Completed,
            'progress' => 100,
        ]);
        AbExperiment::query()->create([
            'ab_product_id' => $draft->id,
            'cabinet_id' => $cabinet->id,
            'name' => 'Draft exp',
            'status' => WbAbTestStatus::Draft,
            'progress' => 30,
        ]);
        AbExperiment::query()->create([
            'ab_product_id' => $running->id,
            'cabinet_id' => $cabinet->id,
            'name' => 'Running exp',
            'status' => WbAbTestStatus::Running,
            'progress' => 80,
        ]);

        $this->actingAs($user)
            ->get('/panel/wb/ab-testing')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Wb/AbTesting/Index')
                ->has('products', 4)
                // running → draft → completed → not_created (even if nm_id would sort idle first)
                ->where('products.0.nm_id', $running->nm_id)
                ->where('products.0.test_status', 'running')
                ->where('products.1.nm_id', $draft->nm_id)
                ->where('products.1.test_status', 'draft')
                ->where('products.2.nm_id', $completed->nm_id)
                ->where('products.2.test_status', 'completed')
                ->where('products.3.nm_id', $idle->nm_id)
                ->where('products.3.test_status', 'not_created'));
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

    public function test_sync_preserves_existing_price_and_rating(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Preserve Metrics Cabinet');

        AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 555001,
            'vendor_code' => 'OLD-VC',
            'title' => 'Old title',
            'price' => 1500,
            'rating' => 4.7,
        ]);

        $mock = Mockery::mock(WbPriceCalculationService::class);
        $mock->shouldReceive('getAllCards')
            ->once()
            ->andReturn([
                'code' => 200,
                'response' => json_encode([
                    'cards' => [
                        [
                            'nmID' => 555001,
                            'vendorCode' => 'NEW-VC',
                            'title' => 'New title',
                            'brand' => 'BrandX',
                            'subjectName' => 'Одежда',
                            'photos' => [
                                ['c246x328' => 'https://example.test/photo.webp'],
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
            'vendor_code' => 'NEW-VC',
            'title' => 'New title',
            'price' => 1500,
            'rating' => 4.7,
        ]);
    }

    public function test_enrich_ratings_updates_feedback_rating(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Ratings Cabinet');

        AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 777001,
            'vendor_code' => 'R1',
            'title' => 'Rated product',
            'rating' => null,
        ]);

        $service = new class(
            app(WbPriceCalculationService::class),
            app(WbAdvertApiClient::class),
            app(\App\Services\Subscriber\Wb\AbTesting\WbAbExperimentEngine::class),
        ) extends WbAbTestingService
        {
            public function apiPostItemRating(string $apiKey, array $body): array
            {
                return [
                    'data' => [
                        'code' => 200,
                        'response' => json_encode([
                            'data' => [
                                'items' => [
                                    [
                                        'nmId' => 777001,
                                        'feedbackRating' => [
                                            'current' => 4.6,
                                            'percentile' => 80.0,
                                        ],
                                    ],
                                ],
                            ],
                        ], JSON_UNESCAPED_UNICODE),
                    ],
                    'function' => 'apiPostItemRating',
                ];
            }
        };

        $result = $service->enrichRatingsFromItemRatingApi($cabinet);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['updated']);
        $this->assertDatabaseHas('wb_ab_products', [
            'cabinet_id' => $cabinet->id,
            'nm_id' => 777001,
            'rating' => 4.6,
        ]);
    }

    public function test_index_with_product_id_returns_selected_product_and_experiments(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Experiments Cabinet');

        $product = AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 333001,
            'vendor_code' => 'SKU-EXP',
            'title' => 'Товар для экспериментов',
            'brand' => 'BrandY',
            'subject_name' => 'Обувь',
        ]);

        AbExperiment::query()->create([
            'ab_product_id' => $product->id,
            'cabinet_id' => $cabinet->id,
            'name' => 'Первый эксперимент',
            'status' => WbAbTestStatus::Draft,
            'progress' => 0,
        ]);

        $this->actingAs($user)
            ->get('/panel/wb/ab-testing?product_id='.$product->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Wb/AbTesting/Index')
                ->where('selectedProduct.id', $product->id)
                ->where('selectedProduct.nm_id', 333001)
                ->has('experiments', 1)
                ->where('experiments.0.name', 'Первый эксперимент')
                ->where('experiments.0.status', 'draft')
                ->where('experiments.0.progress', 0));
    }

    public function test_create_experiment_creates_draft_for_own_product(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Create Exp Cabinet');

        $product = AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 444001,
            'vendor_code' => 'SKU-NEW',
            'title' => 'Новый товар',
        ]);

        $this->actingAs($user)
            ->post('/panel/wb/ab-testing/experiments', [
                'product_id' => $product->id,
            ])
            ->assertRedirect('/panel/wb/ab-testing?product_id='.$product->id)
            ->assertSessionHas('success')
            ->assertSessionHas('createdExperiment');

        $this->assertDatabaseHas('wb_ab_experiments', [
            'ab_product_id' => $product->id,
            'cabinet_id' => $cabinet->id,
            'status' => 'draft',
            'progress' => 0,
        ]);
    }

    public function test_cannot_create_experiment_for_foreign_product(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $this->createUnifiedCabinet($user, 'Own Cabinet');

        $otherUser = $this->createSubscriberUser(withPermission: true);
        $otherCabinet = $this->createUnifiedCabinet($otherUser, 'Other Cabinet');

        $foreignProduct = AbProduct::query()->create([
            'cabinet_id' => $otherCabinet->id,
            'nm_id' => 555002,
            'vendor_code' => 'FOREIGN',
            'title' => 'Чужой товар',
        ]);

        $this->actingAs($user)
            ->from('/panel/wb/ab-testing')
            ->post('/panel/wb/ab-testing/experiments', [
                'product_id' => $foreignProduct->id,
            ])
            ->assertSessionHasErrors('product_id');

        $this->assertDatabaseMissing('wb_ab_experiments', [
            'ab_product_id' => $foreignProduct->id,
        ]);
    }

    public function test_guest_cannot_create_experiment(): void
    {
        $this->post('/panel/wb/ab-testing/experiments', [
            'product_id' => 1,
        ])->assertRedirect('/login');
    }

    public function test_can_rename_own_experiment_via_json(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Rename Cabinet');

        $product = AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 888001,
            'vendor_code' => 'RN',
            'title' => 'Rename product',
        ]);

        $experiment = AbExperiment::query()->create([
            'ab_product_id' => $product->id,
            'cabinet_id' => $cabinet->id,
            'name' => 'Эксперимент от 01.01.2026 12:00',
            'status' => WbAbTestStatus::Draft,
            'progress' => 0,
        ]);

        $this->actingAs($user)
            ->patchJson('/panel/wb/ab-testing/experiments/'.$experiment->id, [
                'name' => 'Тест главной фото',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('experiment.name', 'Тест главной фото');

        $this->assertDatabaseHas('wb_ab_experiments', [
            'id' => $experiment->id,
            'name' => 'Тест главной фото',
        ]);
    }

    public function test_cannot_rename_foreign_experiment(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $this->createUnifiedCabinet($user, 'Own Rename Cabinet');

        $otherUser = $this->createSubscriberUser(withPermission: true);
        $otherCabinet = $this->createUnifiedCabinet($otherUser, 'Other Rename Cabinet');

        $product = AbProduct::query()->create([
            'cabinet_id' => $otherCabinet->id,
            'nm_id' => 888002,
            'vendor_code' => 'FRN',
            'title' => 'Foreign',
        ]);

        $experiment = AbExperiment::query()->create([
            'ab_product_id' => $product->id,
            'cabinet_id' => $otherCabinet->id,
            'name' => 'Чужой',
            'status' => WbAbTestStatus::Draft,
            'progress' => 0,
        ]);

        $this->actingAs($user)
            ->patchJson('/panel/wb/ab-testing/experiments/'.$experiment->id, [
                'name' => 'Hack',
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('wb_ab_experiments', [
            'id' => $experiment->id,
            'name' => 'Чужой',
        ]);
    }

    public function test_product_list_shows_latest_experiment_status(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Status Cabinet');

        $product = AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 666001,
            'vendor_code' => 'SKU-ST',
            'title' => 'Статусный товар',
        ]);

        AbExperiment::query()->create([
            'ab_product_id' => $product->id,
            'cabinet_id' => $cabinet->id,
            'name' => 'Старый',
            'status' => WbAbTestStatus::Draft,
            'progress' => 0,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        AbExperiment::query()->create([
            'ab_product_id' => $product->id,
            'cabinet_id' => $cabinet->id,
            'name' => 'Актуальный',
            'status' => WbAbTestStatus::Running,
            'progress' => 35,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/panel/wb/ab-testing?search=666001')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('products', 1)
                ->where('products.0.test_status', 'running'));
    }

    public function test_list_campaigns_returns_usable_cabinet_campaigns(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Campaigns List Cabinet');

        $product = AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 900001,
            'vendor_code' => 'AB-SKU',
            'title' => 'AB product',
        ]);

        $experiment = AbExperiment::query()->create([
            'ab_product_id' => $product->id,
            'cabinet_id' => $cabinet->id,
            'name' => 'Draft exp',
            'status' => WbAbTestStatus::Draft,
            'progress' => 0,
        ]);

        AbCampaign::query()->create([
            'cabinet_id' => $cabinet->id,
            'wb_advert_id' => 111,
            'name' => 'Our with product',
            'bid_type' => 'unified',
            'payment_type' => 'cpm',
            'created_by_experiment_id' => $experiment->id,
        ]);

        $client = Mockery::mock(WbAdvertApiClient::class);
        $client->shouldReceive('listAdvertIds')
            ->once()
            ->withArgs(function (string $apiKey, ?array $statuses, ?array $types) {
                return $apiKey === 'test-api-key'
                    && $statuses === WbAdvertApiClient::AB_USABLE_ADVERT_STATUSES
                    && $types === WbAdvertApiClient::AB_USABLE_ADVERT_TYPES;
            })
            ->andReturn([
                'success' => true,
                'code' => 200,
                'ids' => [111, 222, 333, 444, 555],
                'groups' => [],
            ]);
        $client->shouldReceive('getAdvertsBatched')
            ->once()
            ->withArgs(function (string $apiKey, array $ids) {
                sort($ids);

                return $ids === [111, 222, 333, 444, 555];
            })
            ->andReturn([
                'success' => true,
                'code' => 200,
                'adverts' => [
                    [
                        'id' => 111,
                        'status' => 4,
                        'bid_type' => 'unified',
                        'settings' => [
                            'name' => 'With product',
                            'payment_type' => 'cpm',
                        ],
                        'nm_settings' => [
                            ['nm_id' => 900001, 'subject' => ['id' => 1, 'name' => 'Cat']],
                        ],
                    ],
                    [
                        'id' => 222,
                        'status' => 11,
                        'bid_type' => 'manual',
                        'settings' => [
                            'name' => 'Cabinet campaign',
                            'payment_type' => 'cpc',
                        ],
                        'nm_settings' => [
                            ['nm_id' => 111111, 'subject' => ['id' => 1, 'name' => 'Cat']],
                        ],
                    ],
                    [
                        'id' => 333,
                        'status' => 7,
                        'bid_type' => 'unified',
                        'settings' => ['name' => 'Completed', 'payment_type' => 'cpm'],
                        'nm_settings' => [['nm_id' => 900001]],
                    ],
                    [
                        'id' => 444,
                        'status' => 4,
                        'bid_type' => 'unified',
                        'settings' => ['name' => 'Locked nms', 'payment_type' => 'cpm'],
                        'nm_settings' => [['nm_id' => 555555]],
                        'restrictions' => ['can_change_nms' => false],
                    ],
                    [
                        'id' => 555,
                        'status' => 9,
                        'bid_type' => 'unified',
                        'settings' => ['name' => 'Active with product', 'payment_type' => 'cpm'],
                        'nm_settings' => [['nm_id' => 900001]],
                    ],
                ],
                'messages' => [],
            ]);

        $this->app->instance(WbAdvertApiClient::class, $client);

        $response = $this->actingAs($user)
            ->getJson('/panel/wb/ab-testing/campaigns?experiment_id='.$experiment->id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('default_campaign_name', 'A/B тест — AB-SKU');

        $campaigns = $response->json('campaigns');
        $this->assertIsArray($campaigns);
        $ids = collect($campaigns)->pluck('id')->all();
        $this->assertEqualsCanonicalizing([111, 222, 555], $ids);
        $this->assertNotContains(333, $ids);
        $this->assertNotContains(444, $ids);

        $byId = collect($campaigns)->keyBy('id');
        $this->assertTrue($byId[111]['contains_product']);
        $this->assertTrue($byId[111]['can_select']);
        $this->assertTrue($byId[111]['can_delete']);
        $this->assertTrue($byId[111]['created_by_tool']);
        $this->assertTrue($byId[222]['can_select']);
        $this->assertFalse($byId[222]['contains_product']);
        $this->assertFalse($byId[222]['can_delete']);
        $this->assertTrue($byId[555]['can_select']);
        $this->assertTrue($byId[555]['contains_product']);
        $this->assertFalse($byId[555]['can_edit_nms']);
        $this->assertTrue($byId[555]['can_pause']);
    }

    public function test_create_campaign_registers_and_binds_without_start(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Create Campaign Cabinet');

        $product = AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 900002,
            'vendor_code' => 'CREATE-SKU',
            'title' => 'Create product',
        ]);

        $experiment = AbExperiment::query()->create([
            'ab_product_id' => $product->id,
            'cabinet_id' => $cabinet->id,
            'name' => 'Create draft',
            'status' => WbAbTestStatus::Draft,
            'progress' => 0,
        ]);

        $client = Mockery::mock(WbAdvertApiClient::class);
        $client->shouldReceive('createSeacatCampaign')
            ->once()
            ->withArgs(function (string $apiKey, array $payload) use ($product) {
                return $apiKey === 'test-api-key'
                    && ($payload['nms'][0] ?? null) === (int) $product->nm_id
                    && ($payload['bid_type'] ?? null) === 'unified'
                    && ($payload['payment_type'] ?? null) === 'cpm';
            })
            ->andReturn([
                'success' => true,
                'code' => 200,
                'advert_id' => 555666,
                'data' => 555666,
            ]);
        $client->shouldNotReceive('depositBudget');

        $this->app->instance(WbAdvertApiClient::class, $client);

        $this->actingAs($user)
            ->postJson('/panel/wb/ab-testing/campaigns', [
                'experiment_id' => $experiment->id,
                'name' => 'A/B тест — CREATE-SKU',
                'bid_type' => 'unified',
                'payment_type' => 'cpm',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('experiment.wb_advert_id', 555666)
            ->assertJsonPath('experiment.progress', 30);

        $this->assertDatabaseHas('wb_ab_experiments', [
            'id' => $experiment->id,
            'wb_advert_id' => 555666,
            'wb_advert_name' => 'A/B тест — CREATE-SKU',
            'progress' => 30,
        ]);

        $this->assertDatabaseHas('wb_ab_campaigns', [
            'cabinet_id' => $cabinet->id,
            'wb_advert_id' => 555666,
            'name' => 'A/B тест — CREATE-SKU',
            'created_by_experiment_id' => $experiment->id,
        ]);
    }

    public function test_create_cpc_campaign_forces_manual_bid_type(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Create CPC Campaign Cabinet');

        $product = AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 900012,
            'vendor_code' => 'CPC-SKU',
            'title' => 'CPC product',
        ]);

        $experiment = AbExperiment::query()->create([
            'ab_product_id' => $product->id,
            'cabinet_id' => $cabinet->id,
            'name' => 'Create CPC draft',
            'status' => WbAbTestStatus::Draft,
            'progress' => 0,
        ]);

        $client = Mockery::mock(WbAdvertApiClient::class);
        $client->shouldReceive('createSeacatCampaign')
            ->once()
            ->withArgs(function (string $apiKey, array $payload) use ($product) {
                return $apiKey === 'test-api-key'
                    && ($payload['nms'][0] ?? null) === (int) $product->nm_id
                    // Client may still send unified; service must coerce for CPC.
                    && ($payload['bid_type'] ?? null) === 'manual'
                    && ($payload['payment_type'] ?? null) === 'cpc'
                    && ($payload['placement_types'] ?? null) === ['search'];
            })
            ->andReturn([
                'success' => true,
                'code' => 200,
                'advert_id' => 555777,
                'data' => 555777,
            ]);
        $client->shouldNotReceive('depositBudget');

        $this->app->instance(WbAdvertApiClient::class, $client);

        $this->actingAs($user)
            ->postJson('/panel/wb/ab-testing/campaigns', [
                'experiment_id' => $experiment->id,
                'name' => 'A/B тест — CPC-SKU',
                'bid_type' => 'unified',
                'payment_type' => 'cpc',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('experiment.wb_advert_id', 555777);

        $this->assertDatabaseHas('wb_ab_campaigns', [
            'cabinet_id' => $cabinet->id,
            'wb_advert_id' => 555777,
            'bid_type' => 'manual',
            'payment_type' => 'cpc',
            'created_by_experiment_id' => $experiment->id,
        ]);
    }

    public function test_prepare_campaign_adds_nm_without_removing_others(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Prepare Cabinet');

        $product = AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 900003,
            'vendor_code' => 'PREP-SKU',
            'title' => 'Prepare product',
        ]);

        $experiment = AbExperiment::query()->create([
            'ab_product_id' => $product->id,
            'cabinet_id' => $cabinet->id,
            'name' => 'Prepare draft',
            'status' => WbAbTestStatus::Draft,
            'progress' => 0,
        ]);

        $client = Mockery::mock(WbAdvertApiClient::class);
        $client->shouldReceive('getAdverts')
            ->twice()
            ->andReturn(
                [
                    'success' => true,
                    'code' => 200,
                    'data' => [
                        'adverts' => [
                            [
                                'id' => 777001,
                                'status' => 4,
                                'bid_type' => 'unified',
                                'settings' => ['name' => 'Reusable', 'payment_type' => 'cpm'],
                                'nm_settings' => [
                                    ['nm_id' => 111111],
                                    ['nm_id' => 222222],
                                ],
                            ],
                        ],
                    ],
                ],
                [
                    'success' => true,
                    'code' => 200,
                    'data' => [
                        'adverts' => [
                            [
                                'id' => 777001,
                                'status' => 4,
                                'bid_type' => 'unified',
                                'settings' => ['name' => 'Reusable', 'payment_type' => 'cpm'],
                                'nm_settings' => [
                                    ['nm_id' => 111111],
                                    ['nm_id' => 222222],
                                    ['nm_id' => 900003],
                                ],
                            ],
                        ],
                    ],
                ],
            );
        $client->shouldReceive('patchAuctionNms')
            ->once()
            ->withArgs(function (string $apiKey, int $advertId, array $add, array $delete) {
                return $advertId === 777001
                    && $add === [900003]
                    && $delete === [];
            })
            ->andReturn(['success' => true, 'code' => 200, 'data' => []]);

        $this->app->instance(WbAdvertApiClient::class, $client);

        $this->actingAs($user)
            ->postJson('/panel/wb/ab-testing/campaigns/777001/prepare', [
                'experiment_id' => $experiment->id,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('experiment.wb_advert_id', 777001)
            ->assertJsonPath('campaign.contains_product', true);

        $this->assertDatabaseHas('wb_ab_experiments', [
            'id' => $experiment->id,
            'wb_advert_id' => 777001,
        ]);
        $this->assertDatabaseHas('wb_ab_campaigns', [
            'cabinet_id' => $cabinet->id,
            'wb_advert_id' => 777001,
            'created_by_experiment_id' => null,
        ]);
    }

    public function test_prepare_rejects_active_wb_campaign(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Active Prepare Cabinet');

        $product = AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 900013,
            'vendor_code' => 'ACT-SKU',
            'title' => 'Active product',
        ]);

        $experiment = AbExperiment::query()->create([
            'ab_product_id' => $product->id,
            'cabinet_id' => $cabinet->id,
            'name' => 'Active draft',
            'status' => WbAbTestStatus::Draft,
            'progress' => 0,
        ]);

        AbCampaign::query()->create([
            'cabinet_id' => $cabinet->id,
            'wb_advert_id' => 777009,
            'name' => 'Active on WB',
            'bid_type' => 'unified',
            'payment_type' => 'cpm',
        ]);

        $client = Mockery::mock(WbAdvertApiClient::class);
        $client->shouldReceive('getAdverts')
            ->once()
            ->andReturn([
                'success' => true,
                'code' => 200,
                'data' => [
                    'adverts' => [
                        [
                            'id' => 777009,
                            'status' => 9,
                            'bid_type' => 'unified',
                            'settings' => ['name' => 'Active on WB', 'payment_type' => 'cpm'],
                            'nm_settings' => [['nm_id' => 111]],
                        ],
                    ],
                ],
            ]);
        $client->shouldNotReceive('patchAuctionNms');

        $this->app->instance(WbAdvertApiClient::class, $client);

        $this->actingAs($user)
            ->postJson('/panel/wb/ab-testing/campaigns/777009/prepare', [
                'experiment_id' => $experiment->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_prepare_existing_campaign_with_product_binds_without_patch(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Foreign Advert Cabinet');

        $product = AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 900023,
            'vendor_code' => 'FRN-ADV',
            'title' => 'Foreign advert product',
        ]);

        $experiment = AbExperiment::query()->create([
            'ab_product_id' => $product->id,
            'cabinet_id' => $cabinet->id,
            'name' => 'Draft',
            'status' => WbAbTestStatus::Draft,
            'progress' => 0,
        ]);

        $client = Mockery::mock(WbAdvertApiClient::class);
        $client->shouldReceive('getAdverts')
            ->twice()
            ->andReturn([
                'success' => true,
                'code' => 200,
                'data' => [
                    'adverts' => [
                        [
                            'id' => 999999,
                            'status' => 9,
                            'bid_type' => 'unified',
                            'settings' => ['name' => 'Existing active', 'payment_type' => 'cpm'],
                            'nm_settings' => [['nm_id' => 900023]],
                        ],
                    ],
                ],
            ]);
        $client->shouldNotReceive('patchAuctionNms');

        $this->app->instance(WbAdvertApiClient::class, $client);

        $this->actingAs($user)
            ->postJson('/panel/wb/ab-testing/campaigns/999999/prepare', [
                'experiment_id' => $experiment->id,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('experiment.wb_advert_id', 999999)
            ->assertJsonPath('experiment.campaign_created_by_tool', false);

        $this->assertDatabaseHas('wb_ab_campaigns', [
            'cabinet_id' => $cabinet->id,
            'wb_advert_id' => 999999,
            'created_by_experiment_id' => null,
        ]);
    }

    public function test_add_product_to_our_campaign_and_bind(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Add Nms Cabinet');

        $product = AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 900033,
            'vendor_code' => 'ADD-SKU',
            'title' => 'Add product',
        ]);

        $experiment = AbExperiment::query()->create([
            'ab_product_id' => $product->id,
            'cabinet_id' => $cabinet->id,
            'name' => 'Add draft',
            'status' => WbAbTestStatus::Draft,
            'progress' => 0,
        ]);

        AbCampaign::query()->create([
            'cabinet_id' => $cabinet->id,
            'wb_advert_id' => 777001,
            'name' => 'Target',
            'bid_type' => 'unified',
            'payment_type' => 'cpm',
        ]);

        $client = Mockery::mock(WbAdvertApiClient::class);
        $client->shouldReceive('getAdverts')
            ->twice()
            ->andReturn(
                [
                    'success' => true,
                    'code' => 200,
                    'data' => [
                        'adverts' => [
                            [
                                'id' => 777001,
                                'status' => 4,
                                'bid_type' => 'unified',
                                'settings' => ['name' => 'Target', 'payment_type' => 'cpm'],
                                'nm_settings' => [],
                            ],
                        ],
                    ],
                ],
                [
                    'success' => true,
                    'code' => 200,
                    'data' => [
                        'adverts' => [
                            [
                                'id' => 777001,
                                'status' => 4,
                                'bid_type' => 'unified',
                                'settings' => ['name' => 'Target', 'payment_type' => 'cpm'],
                                'nm_settings' => [
                                    ['nm_id' => 900033],
                                ],
                            ],
                        ],
                    ],
                ],
            );
        $client->shouldReceive('patchAuctionNms')
            ->once()
            ->withArgs(function (string $apiKey, int $advertId, array $add, array $delete) {
                return $advertId === 777001 && $add === [900033] && $delete === [];
            })
            ->andReturn(['success' => true, 'code' => 200, 'data' => []]);

        $this->app->instance(WbAdvertApiClient::class, $client);

        $this->actingAs($user)
            ->postJson('/panel/wb/ab-testing/campaigns/777001/nms', [
                'experiment_id' => $experiment->id,
                'bind' => true,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('experiment.wb_advert_id', 777001);

        $this->assertDatabaseHas('wb_ab_experiments', [
            'id' => $experiment->id,
            'wb_advert_id' => 777001,
        ]);
    }

    public function test_remove_product_requires_confirm(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Remove Confirm Cabinet');

        $product = AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 900014,
            'vendor_code' => 'RMC-SKU',
            'title' => 'Remove confirm product',
        ]);

        $experiment = AbExperiment::query()->create([
            'ab_product_id' => $product->id,
            'cabinet_id' => $cabinet->id,
            'name' => 'Remove confirm draft',
            'status' => WbAbTestStatus::Draft,
            'progress' => 30,
            'wb_advert_id' => 888011,
            'wb_advert_name' => 'Bound campaign',
            'campaign_bound_at' => now(),
        ]);

        $this->actingAs($user)
            ->deleteJson('/panel/wb/ab-testing/campaigns/888011/nms', [
                'experiment_id' => $experiment->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_remove_product_unbinds_experiment(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Remove Nms Cabinet');

        $product = AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 900004,
            'vendor_code' => 'RM-SKU',
            'title' => 'Remove product',
        ]);

        $experiment = AbExperiment::query()->create([
            'ab_product_id' => $product->id,
            'cabinet_id' => $cabinet->id,
            'name' => 'Remove draft',
            'status' => WbAbTestStatus::Draft,
            'progress' => 30,
            'wb_advert_id' => 888001,
            'wb_advert_name' => 'Bound campaign',
            'campaign_bound_at' => now(),
        ]);

        AbCampaign::query()->create([
            'cabinet_id' => $cabinet->id,
            'wb_advert_id' => 888001,
            'name' => 'Bound campaign',
            'bid_type' => 'unified',
            'payment_type' => 'cpm',
        ]);

        $client = Mockery::mock(WbAdvertApiClient::class);
        $client->shouldReceive('getAdverts')
            ->once()
            ->andReturn([
                'success' => true,
                'code' => 200,
                'data' => [
                    'adverts' => [
                        [
                            'id' => 888001,
                            'status' => 11,
                            'bid_type' => 'unified',
                            'settings' => ['name' => 'Bound campaign', 'payment_type' => 'cpm'],
                            'nm_settings' => [
                                ['nm_id' => 900004],
                            ],
                        ],
                    ],
                ],
            ]);
        $client->shouldReceive('patchAuctionNms')
            ->once()
            ->withArgs(function (string $apiKey, int $advertId, array $add, array $delete) {
                return $advertId === 888001 && $add === [] && $delete === [900004];
            })
            ->andReturn(['success' => true, 'code' => 200, 'data' => []]);

        $this->app->instance(WbAdvertApiClient::class, $client);

        $this->actingAs($user)
            ->deleteJson('/panel/wb/ab-testing/campaigns/888001/nms', [
                'experiment_id' => $experiment->id,
                'confirm' => true,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('experiment.wb_advert_id', null);

        $this->assertDatabaseHas('wb_ab_experiments', [
            'id' => $experiment->id,
            'wb_advert_id' => null,
            'progress' => 0,
        ]);
    }

    public function test_cannot_bind_campaign_to_running_experiment(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Running Bind Cabinet');

        $product = AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 900005,
            'vendor_code' => 'RUN-SKU',
            'title' => 'Running product',
        ]);

        $experiment = AbExperiment::query()->create([
            'ab_product_id' => $product->id,
            'cabinet_id' => $cabinet->id,
            'name' => 'Running',
            'status' => WbAbTestStatus::Running,
            'progress' => 50,
        ]);

        $this->actingAs($user)
            ->postJson('/panel/wb/ab-testing/experiments/'.$experiment->id.'/campaign', [
                'advert_id' => 123,
                'add_product' => true,
            ])
            ->assertStatus(422);
    }

    public function test_prepare_rejects_campaign_busy_by_running_ab(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Busy Campaign Cabinet');

        $productA = AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 900051,
            'vendor_code' => 'BUSY-A',
            'title' => 'Product A',
        ]);
        $productB = AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 900052,
            'vendor_code' => 'BUSY-B',
            'title' => 'Product B',
        ]);

        AbExperiment::query()->create([
            'ab_product_id' => $productA->id,
            'cabinet_id' => $cabinet->id,
            'name' => 'Running owner',
            'status' => WbAbTestStatus::Running,
            'progress' => 50,
            'wb_advert_id' => 555001,
            'wb_advert_name' => 'Shared',
        ]);

        $draft = AbExperiment::query()->create([
            'ab_product_id' => $productB->id,
            'cabinet_id' => $cabinet->id,
            'name' => 'Draft wants reuse',
            'status' => WbAbTestStatus::Draft,
            'progress' => 0,
        ]);

        AbCampaign::query()->create([
            'cabinet_id' => $cabinet->id,
            'wb_advert_id' => 555001,
            'name' => 'Shared',
            'bid_type' => 'unified',
            'payment_type' => 'cpm',
        ]);

        $client = Mockery::mock(WbAdvertApiClient::class);
        $client->shouldReceive('getAdverts')
            ->once()
            ->andReturn([
                'success' => true,
                'code' => 200,
                'data' => [
                    'adverts' => [
                        [
                            'id' => 555001,
                            'status' => 4,
                            'bid_type' => 'unified',
                            'settings' => ['name' => 'Shared', 'payment_type' => 'cpm'],
                            'nm_settings' => [['nm_id' => 900051]],
                        ],
                    ],
                ],
            ]);
        $client->shouldNotReceive('patchAuctionNms');

        $this->app->instance(WbAdvertApiClient::class, $client);

        $this->actingAs($user)
            ->postJson('/panel/wb/ab-testing/campaigns/555001/prepare', [
                'experiment_id' => $draft->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_cannot_delete_existing_cabinet_campaign_not_created_by_tool(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'No Delete Foreign');

        AbCampaign::query()->create([
            'cabinet_id' => $cabinet->id,
            'wb_advert_id' => 444001,
            'name' => 'Seller campaign',
            'bid_type' => 'unified',
            'payment_type' => 'cpm',
            'created_by_experiment_id' => null,
        ]);

        $client = Mockery::mock(WbAdvertApiClient::class);
        $client->shouldNotReceive('deleteAdvert');
        $this->app->instance(WbAdvertApiClient::class, $client);

        $this->actingAs($user)
            ->deleteJson('/panel/wb/ab-testing/campaigns/444001')
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_guest_cannot_list_campaigns(): void
    {
        $this->get('/panel/wb/ab-testing/campaigns?experiment_id=1')
            ->assertRedirect('/login');
    }

    public function test_index_with_experiment_id_returns_selected_experiment(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Deep Link Cabinet');

        $product = AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 900006,
            'vendor_code' => 'DL-SKU',
            'title' => 'Deep link product',
        ]);

        $experiment = AbExperiment::query()->create([
            'ab_product_id' => $product->id,
            'cabinet_id' => $cabinet->id,
            'name' => 'Deep link exp',
            'status' => WbAbTestStatus::Draft,
            'progress' => 30,
            'wb_advert_id' => 424242,
            'wb_advert_name' => 'Linked',
        ]);

        $this->actingAs($user)
            ->get('/panel/wb/ab-testing?product_id='.$product->id.'&experiment_id='.$experiment->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Wb/AbTesting/Index')
                ->where('selectedExperiment.id', $experiment->id)
                ->where('selectedExperiment.wb_advert_id', 424242)
                ->where('filters.experiment_id', $experiment->id));
    }

    public function test_upload_photos_stores_on_private_disk_and_updates_progress(): void
    {
        Storage::fake('private');

        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Photos Cabinet');
        [$product, $experiment] = $this->createDraftExperimentWithCampaign($cabinet);

        $fileA = UploadedFile::fake()->image('variant-a.jpg', 800, 800);
        $fileB = UploadedFile::fake()->image('variant-b.png', 600, 600);

        $this->actingAs($user)
            ->post("/panel/wb/ab-testing/experiments/{$experiment->id}/photos", [
                'photos' => [$fileA, $fileB],
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('experiment.photos_count', 2)
            ->assertJsonPath('experiment.progress', 50)
            ->assertJsonPath('experiment.can_continue_photos', true)
            ->assertJsonCount(2, 'photos');

        $this->assertDatabaseCount('wb_ab_experiment_photos', 2);

        $photos = AbExperimentPhoto::query()
            ->where('ab_experiment_id', $experiment->id)
            ->orderBy('sort_order')
            ->get();

        $this->assertCount(2, $photos);
        foreach ($photos as $photo) {
            $this->assertSame('private', $photo->disk);
            Storage::disk('private')->assertExists($photo->path);
        }

        $list = $this->actingAs($user)
            ->getJson("/panel/wb/ab-testing/experiments/{$experiment->id}/photos")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('photos');

        $this->assertStringContainsString('/panel/wb/ab-testing/media/', $list[0]['preview_url']);
        $this->assertStringNotContainsString('/storage/', $list[0]['preview_url']);
    }

    public function test_upload_rejects_seventh_photo(): void
    {
        Storage::fake('private');

        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Max Photos Cabinet');
        [, $experiment] = $this->createDraftExperimentWithCampaign($cabinet);

        $files = [];
        for ($i = 0; $i < 6; $i++) {
            $files[] = UploadedFile::fake()->image("p{$i}.jpg");
        }

        $this->actingAs($user)
            ->post("/panel/wb/ab-testing/experiments/{$experiment->id}/photos", [
                'photos' => $files,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('experiment.photos_count', 6);

        $this->actingAs($user)
            ->post("/panel/wb/ab-testing/experiments/{$experiment->id}/photos", [
                'photos' => [UploadedFile::fake()->image('extra.jpg')],
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_delete_photo_compacts_sort_order_and_drops_progress(): void
    {
        Storage::fake('private');

        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Delete Photos Cabinet');
        [, $experiment] = $this->createDraftExperimentWithCampaign($cabinet);

        $this->actingAs($user)
            ->post("/panel/wb/ab-testing/experiments/{$experiment->id}/photos", [
                'photos' => [
                    UploadedFile::fake()->image('a.jpg'),
                    UploadedFile::fake()->image('b.jpg'),
                ],
            ])
            ->assertOk();

        $photos = AbExperimentPhoto::query()
            ->where('ab_experiment_id', $experiment->id)
            ->orderBy('sort_order')
            ->get();

        $this->actingAs($user)
            ->delete("/panel/wb/ab-testing/experiments/{$experiment->id}/photos/{$photos[0]->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('experiment.photos_count', 1)
            ->assertJsonPath('experiment.progress', 30)
            ->assertJsonPath('experiment.can_continue_photos', false);

        $remaining = AbExperimentPhoto::query()
            ->where('ab_experiment_id', $experiment->id)
            ->orderBy('sort_order')
            ->get();

        $this->assertCount(1, $remaining);
        $this->assertSame(0, (int) $remaining[0]->sort_order);
        $this->assertSame((int) $photos[1]->id, (int) $remaining[0]->id);
        Storage::disk('private')->assertMissing($photos[0]->path);
    }

    public function test_reorder_and_replace_photos(): void
    {
        Storage::fake('private');

        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Reorder Photos Cabinet');
        [, $experiment] = $this->createDraftExperimentWithCampaign($cabinet);

        $this->actingAs($user)
            ->post("/panel/wb/ab-testing/experiments/{$experiment->id}/photos", [
                'photos' => [
                    UploadedFile::fake()->image('first.jpg'),
                    UploadedFile::fake()->image('second.jpg'),
                ],
            ])
            ->assertOk();

        $photos = AbExperimentPhoto::query()
            ->where('ab_experiment_id', $experiment->id)
            ->orderBy('sort_order')
            ->get();

        $this->actingAs($user)
            ->patchJson("/panel/wb/ab-testing/experiments/{$experiment->id}/photos/reorder", [
                'order' => [$photos[1]->id, $photos[0]->id],
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('photos.0.id', $photos[1]->id)
            ->assertJsonPath('photos.1.id', $photos[0]->id);

        $oldPath = $photos[0]->path;

        $this->actingAs($user)
            ->post("/panel/wb/ab-testing/experiments/{$experiment->id}/photos/{$photos[0]->id}", [
                'photo' => UploadedFile::fake()->image('replaced.webp'),
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $photos[0]->refresh();
        $this->assertNotSame($oldPath, $photos[0]->path);
        Storage::disk('private')->assertMissing($oldPath);
        Storage::disk('private')->assertExists($photos[0]->path);
    }

    public function test_media_route_requires_auth_and_cabinet_ownership(): void
    {
        Storage::fake('private');

        $owner = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($owner, 'Media Owner Cabinet');
        [, $experiment] = $this->createDraftExperimentWithCampaign($cabinet);

        $this->actingAs($owner)
            ->post("/panel/wb/ab-testing/experiments/{$experiment->id}/photos", [
                'photos' => [UploadedFile::fake()->image('secret.jpg')],
            ])
            ->assertOk();

        $photo = AbExperimentPhoto::query()->where('ab_experiment_id', $experiment->id)->firstOrFail();

        $this->actingAs($owner)
            ->get("/panel/wb/ab-testing/media/{$photo->id}")
            ->assertOk();

        $other = $this->createSubscriberUser(withPermission: true);
        $this->createUnifiedCabinet($other, 'Other Media Cabinet');

        $this->actingAs($other)
            ->get("/panel/wb/ab-testing/media/{$photo->id}")
            ->assertNotFound();

        auth()->logout();
        $this->get("/panel/wb/ab-testing/media/{$photo->id}")
            ->assertRedirect('/login');
    }

    public function test_running_experiment_cannot_upload_photos(): void
    {
        Storage::fake('private');

        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Running Photos Cabinet');

        $product = AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 900100,
            'vendor_code' => 'RUN-SKU',
            'title' => 'Running product',
        ]);

        $experiment = AbExperiment::query()->create([
            'ab_product_id' => $product->id,
            'cabinet_id' => $cabinet->id,
            'name' => 'Running exp',
            'status' => WbAbTestStatus::Running,
            'progress' => 60,
            'wb_advert_id' => 999001,
            'wb_advert_name' => 'Live',
        ]);

        $this->actingAs($user)
            ->post("/panel/wb/ab-testing/experiments/{$experiment->id}/photos", [
                'photos' => [UploadedFile::fake()->image('nope.jpg')],
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_running_experiment_can_delete_non_current_photo(): void
    {
        Storage::fake('private');

        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Running Delete Non-Current');
        $cabinet->apikey = 'test-api-key';
        $cabinet->save();

        $product = AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 900110,
            'vendor_code' => 'RUN-DEL-NC',
            'title' => 'Running delete product',
        ]);

        $experiment = AbExperiment::query()->create([
            'ab_product_id' => $product->id,
            'cabinet_id' => $cabinet->id,
            'name' => 'Running delete',
            'status' => WbAbTestStatus::Running,
            'progress' => 40,
            'wb_advert_id' => 999010,
            'wb_advert_name' => 'Live',
            'started_at' => now()->subHour(),
            'impressions_per_photo' => 10000,
            'impressions_per_round' => 1000,
            'round_minutes' => 60,
            'cpm' => 350,
        ]);

        Storage::disk('private')->put('wb/ab-testing/run-a.jpg', 'a');
        Storage::disk('private')->put('wb/ab-testing/run-b.jpg', 'b');
        Storage::disk('private')->put('wb/ab-testing/run-c.jpg', 'c');

        $photoA = AbExperimentPhoto::query()->create([
            'ab_experiment_id' => $experiment->id,
            'cabinet_id' => $cabinet->id,
            'sort_order' => 0,
            'disk' => 'private',
            'path' => 'wb/ab-testing/run-a.jpg',
            'original_name' => 'a.jpg',
            'mime' => 'image/jpeg',
            'size' => 1,
        ]);
        $photoB = AbExperimentPhoto::query()->create([
            'ab_experiment_id' => $experiment->id,
            'cabinet_id' => $cabinet->id,
            'sort_order' => 1,
            'disk' => 'private',
            'path' => 'wb/ab-testing/run-b.jpg',
            'original_name' => 'b.jpg',
            'mime' => 'image/jpeg',
            'size' => 1,
        ]);
        $photoC = AbExperimentPhoto::query()->create([
            'ab_experiment_id' => $experiment->id,
            'cabinet_id' => $cabinet->id,
            'sort_order' => 2,
            'disk' => 'private',
            'path' => 'wb/ab-testing/run-c.jpg',
            'original_name' => 'c.jpg',
            'mime' => 'image/jpeg',
            'size' => 1,
        ]);

        // Текущее на карточке — A; удаляем B (не текущее) — upload в WB не нужен.
        \App\Models\Subscribers\Wb\AbTesting\AbExperimentCycle::query()->create([
            'ab_experiment_id' => $experiment->id,
            'cabinet_id' => $cabinet->id,
            'ab_experiment_photo_id' => $photoA->id,
            'sequence' => 1,
            'started_at' => now()->subMinutes(20),
            'views_start' => 0,
            'clicks_start' => 0,
            'spend_start' => 0,
            'orders_start' => 0,
            'views_end' => 50,
            'clicks_end' => 2,
        ]);

        $mediaApi = Mockery::mock(\App\Services\Wb\WbContentMediaClient::class);
        $mediaApi->shouldNotReceive('uploadMediaFile');
        $this->app->instance(\App\Services\Wb\WbContentMediaClient::class, $mediaApi);

        $this->actingAs($user)
            ->delete("/panel/wb/ab-testing/experiments/{$experiment->id}/photos/{$photoB->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('experiment.photos_count', 2)
            ->assertJsonPath('experiment.can_delete_photos', true)
            ->assertJsonPath('experiment.status', 'running');

        $this->assertDatabaseMissing('wb_ab_experiment_photos', ['id' => $photoB->id]);
        $this->assertDatabaseHas('wb_ab_experiment_photos', ['id' => $photoA->id]);
        $this->assertDatabaseHas('wb_ab_experiment_photos', ['id' => $photoC->id]);
        Storage::disk('private')->assertMissing('wb/ab-testing/run-b.jpg');

        // Open cycle всё ещё на A.
        $open = \App\Models\Subscribers\Wb\AbTesting\AbExperimentCycle::query()
            ->where('ab_experiment_id', $experiment->id)
            ->whereNull('ended_at')
            ->first();
        $this->assertNotNull($open);
        $this->assertSame((int) $photoA->id, (int) $open->ab_experiment_photo_id);
    }

    public function test_running_experiment_delete_current_photo_switches_to_next(): void
    {
        Storage::fake('private');

        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Running Delete Current');
        $cabinet->apikey = 'test-api-key';
        $cabinet->save();

        $product = AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 900111,
            'vendor_code' => 'RUN-DEL-CUR',
            'title' => 'Running delete current',
        ]);

        $experiment = AbExperiment::query()->create([
            'ab_product_id' => $product->id,
            'cabinet_id' => $cabinet->id,
            'name' => 'Running delete current',
            'status' => WbAbTestStatus::Running,
            'progress' => 40,
            'wb_advert_id' => 999011,
            'wb_advert_name' => 'Live',
            'started_at' => now()->subHour(),
            'impressions_per_photo' => 10000,
            'impressions_per_round' => 1000,
            'round_minutes' => 60,
            'cpm' => 350,
        ]);

        Storage::disk('private')->put('wb/ab-testing/cur-a.jpg', 'a');
        Storage::disk('private')->put('wb/ab-testing/cur-b.jpg', 'b');

        $photoA = AbExperimentPhoto::query()->create([
            'ab_experiment_id' => $experiment->id,
            'cabinet_id' => $cabinet->id,
            'sort_order' => 0,
            'disk' => 'private',
            'path' => 'wb/ab-testing/cur-a.jpg',
            'original_name' => 'a.jpg',
            'mime' => 'image/jpeg',
            'size' => 1,
        ]);
        $photoB = AbExperimentPhoto::query()->create([
            'ab_experiment_id' => $experiment->id,
            'cabinet_id' => $cabinet->id,
            'sort_order' => 1,
            'disk' => 'private',
            'path' => 'wb/ab-testing/cur-b.jpg',
            'original_name' => 'b.jpg',
            'mime' => 'image/jpeg',
            'size' => 1,
        ]);

        \App\Models\Subscribers\Wb\AbTesting\AbExperimentCycle::query()->create([
            'ab_experiment_id' => $experiment->id,
            'cabinet_id' => $cabinet->id,
            'ab_experiment_photo_id' => $photoA->id,
            'sequence' => 1,
            'started_at' => now()->subMinutes(20),
            'views_start' => 10,
            'clicks_start' => 1,
            'spend_start' => 0,
            'orders_start' => 0,
            'views_end' => 80,
            'clicks_end' => 3,
        ]);

        $mediaApi = Mockery::mock(\App\Services\Wb\WbContentMediaClient::class);
        $mediaApi->shouldReceive('uploadMediaFile')
            ->once()
            ->andReturn(['success' => true, 'code' => 200]);
        $this->app->instance(\App\Services\Wb\WbContentMediaClient::class, $mediaApi);

        $this->actingAs($user)
            ->delete("/panel/wb/ab-testing/experiments/{$experiment->id}/photos/{$photoA->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('experiment.photos_count', 1)
            ->assertJsonPath('experiment.current_photo_id', $photoB->id);

        $this->assertDatabaseMissing('wb_ab_experiment_photos', ['id' => $photoA->id]);
        $this->assertDatabaseHas('wb_ab_experiment_photos', ['id' => $photoB->id]);

        $open = \App\Models\Subscribers\Wb\AbTesting\AbExperimentCycle::query()
            ->where('ab_experiment_id', $experiment->id)
            ->whereNull('ended_at')
            ->first();
        $this->assertNotNull($open);
        $this->assertSame((int) $photoB->id, (int) $open->ab_experiment_photo_id);
        $this->assertSame(2, (int) $open->sequence);
    }

    public function test_running_experiment_cannot_delete_last_photo(): void
    {
        Storage::fake('private');

        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Running Delete Last');
        $cabinet->apikey = 'test-api-key';
        $cabinet->save();

        $product = AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 900112,
            'vendor_code' => 'RUN-DEL-LAST',
            'title' => 'Running last photo',
        ]);

        $experiment = AbExperiment::query()->create([
            'ab_product_id' => $product->id,
            'cabinet_id' => $cabinet->id,
            'name' => 'Running last',
            'status' => WbAbTestStatus::Running,
            'progress' => 50,
            'wb_advert_id' => 999012,
            'started_at' => now()->subHour(),
            'impressions_per_photo' => 10000,
            'impressions_per_round' => 1000,
            'round_minutes' => 60,
            'cpm' => 350,
        ]);

        Storage::disk('private')->put('wb/ab-testing/last.jpg', 'x');
        $photo = AbExperimentPhoto::query()->create([
            'ab_experiment_id' => $experiment->id,
            'cabinet_id' => $cabinet->id,
            'sort_order' => 0,
            'disk' => 'private',
            'path' => 'wb/ab-testing/last.jpg',
            'mime' => 'image/jpeg',
            'size' => 1,
        ]);

        \App\Models\Subscribers\Wb\AbTesting\AbExperimentCycle::query()->create([
            'ab_experiment_id' => $experiment->id,
            'cabinet_id' => $cabinet->id,
            'ab_experiment_photo_id' => $photo->id,
            'sequence' => 1,
            'started_at' => now()->subMinutes(10),
            'views_start' => 0,
            'clicks_start' => 0,
            'spend_start' => 0,
            'orders_start' => 0,
        ]);

        $this->actingAs($user)
            ->delete("/panel/wb/ab-testing/experiments/{$experiment->id}/photos/{$photo->id}")
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('wb_ab_experiment_photos', ['id' => $photo->id]);
    }

    public function test_completed_experiment_cannot_delete_photos(): void
    {
        Storage::fake('private');

        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Completed Delete');

        $product = AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 900113,
            'vendor_code' => 'DONE-DEL',
            'title' => 'Completed product',
        ]);

        $experiment = AbExperiment::query()->create([
            'ab_product_id' => $product->id,
            'cabinet_id' => $cabinet->id,
            'name' => 'Completed',
            'status' => WbAbTestStatus::Completed,
            'progress' => 100,
            'finished_at' => now(),
        ]);

        Storage::disk('private')->put('wb/ab-testing/done.jpg', 'x');
        $photo = AbExperimentPhoto::query()->create([
            'ab_experiment_id' => $experiment->id,
            'cabinet_id' => $cabinet->id,
            'sort_order' => 0,
            'disk' => 'private',
            'path' => 'wb/ab-testing/done.jpg',
            'mime' => 'image/jpeg',
            'size' => 1,
        ]);

        $this->actingAs($user)
            ->delete("/panel/wb/ab-testing/experiments/{$experiment->id}/photos/{$photo->id}")
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_media_download_sets_attachment_disposition(): void
    {
        Storage::fake('private');

        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Download Media Cabinet');
        [, $experiment] = $this->createDraftExperimentWithCampaign($cabinet);

        $this->actingAs($user)
            ->post("/panel/wb/ab-testing/experiments/{$experiment->id}/photos", [
                'photos' => [UploadedFile::fake()->image('download-me.jpg')],
            ])
            ->assertOk();

        $photo = AbExperimentPhoto::query()->where('ab_experiment_id', $experiment->id)->firstOrFail();

        $this->actingAs($user)
            ->get("/panel/wb/ab-testing/media/{$photo->id}?download=1")
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="download-me.jpg"');
    }

    public function test_update_settings_persists_and_sets_progress_with_photos(): void
    {
        Storage::fake('private');

        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Settings Cabinet');
        [, $experiment] = $this->createDraftExperimentWithCampaign($cabinet);

        $this->actingAs($user)
            ->post("/panel/wb/ab-testing/experiments/{$experiment->id}/photos", [
                'photos' => [
                    UploadedFile::fake()->image('a.jpg'),
                    UploadedFile::fake()->image('b.jpg'),
                ],
            ])
            ->assertOk()
            ->assertJsonPath('experiment.progress', 50);

        $this->actingAs($user)
            ->patchJson("/panel/wb/ab-testing/experiments/{$experiment->id}/settings", [
                'impressions_per_photo' => 100000,
                'impressions_per_round' => 10000,
                'round_minutes' => 60,
                'cpm' => 350,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('experiment.settings_ready', true)
            ->assertJsonPath('experiment.settings.impressions_per_photo', 100000)
            ->assertJsonPath('experiment.settings.cpm', 350)
            ->assertJsonPath('experiment.progress', 70)
            ->assertJsonPath('experiment.can_continue_workspace', true);

        $this->assertDatabaseHas('wb_ab_experiments', [
            'id' => $experiment->id,
            'impressions_per_photo' => 100000,
            'impressions_per_round' => 10000,
            'round_minutes' => 60,
            'cpm' => 350,
            'progress' => 70,
        ]);
    }

    public function test_settings_validation_rejects_per_round_above_target(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Settings Validation Cabinet');
        [, $experiment] = $this->createDraftExperimentWithCampaign($cabinet);

        $this->actingAs($user)
            ->patchJson("/panel/wb/ab-testing/experiments/{$experiment->id}/settings", [
                'impressions_per_photo' => 1000,
                'impressions_per_round' => 5000,
                'round_minutes' => 60,
                'cpm' => 350,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['impressions_per_round']);
    }

    public function test_cpm_campaign_rejects_bid_below_50(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'CPM Bid Min Cabinet');
        [, $experiment] = $this->createDraftExperimentWithCampaign($cabinet, paymentType: 'cpm');

        $this->actingAs($user)
            ->patchJson("/panel/wb/ab-testing/experiments/{$experiment->id}/settings", [
                'impressions_per_photo' => 100000,
                'impressions_per_round' => 10000,
                'round_minutes' => 60,
                'cpm' => 10,
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.cpm.0', 'CPM: от 50 до 50 000 ₽.');
    }

    public function test_cpc_campaign_allows_bid_from_1_ruble(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'CPC Bid Min Cabinet');
        [, $experiment] = $this->createDraftExperimentWithCampaign($cabinet, paymentType: 'cpc');

        $response = $this->actingAs($user)
            ->patchJson("/panel/wb/ab-testing/experiments/{$experiment->id}/settings", [
                'impressions_per_photo' => 100000,
                'impressions_per_round' => 10000,
                'round_minutes' => 60,
                'cpm' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('experiment.campaign_payment_type', 'cpc')
            ->assertJsonPath('experiment.settings.cpm', 1);

        $summary = (string) $response->json('experiment.settings_summary');
        $this->assertStringContainsString('CPC', $summary);
        $this->assertStringContainsString('1 ₽', $summary);

        $this->assertDatabaseHas('wb_ab_experiments', [
            'id' => $experiment->id,
            'cpm' => 1,
        ]);
    }

    public function test_running_experiment_cannot_update_settings(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Settings Running Cabinet');

        $product = AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 900400,
            'vendor_code' => 'SET-RUN',
            'title' => 'Settings running product',
        ]);

        $experiment = AbExperiment::query()->create([
            'ab_product_id' => $product->id,
            'cabinet_id' => $cabinet->id,
            'name' => 'Running settings',
            'status' => WbAbTestStatus::Running,
            'progress' => 80,
            'impressions_per_photo' => 100000,
            'impressions_per_round' => 10000,
            'round_minutes' => 60,
            'cpm' => 350,
        ]);

        $this->actingAs($user)
            ->patchJson("/panel/wb/ab-testing/experiments/{$experiment->id}/settings", [
                'impressions_per_photo' => 200000,
                'impressions_per_round' => 10000,
                'round_minutes' => 60,
                'cpm' => 400,
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_only_one_running_experiment_per_product_allowed_to_start(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'One Running Cabinet');

        $product = AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 900300,
            'vendor_code' => 'ONE-RUN',
            'title' => 'One running product',
        ]);

        AbExperiment::query()->create([
            'ab_product_id' => $product->id,
            'cabinet_id' => $cabinet->id,
            'name' => 'Already running',
            'status' => WbAbTestStatus::Running,
            'progress' => 70,
        ]);

        $draft = AbExperiment::query()->create([
            'ab_product_id' => $product->id,
            'cabinet_id' => $cabinet->id,
            'name' => 'Another draft',
            'status' => WbAbTestStatus::Draft,
            'progress' => 50,
        ]);

        $service = app(WbAbTestingService::class);

        $found = $service->findRunningExperimentForProduct((int) $cabinet->id, (int) $product->id);
        $this->assertNotNull($found);
        $this->assertSame('Already running', $found->name);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $service->assertCanStartExperiment($draft);
    }

    public function test_cannot_start_when_advert_used_by_another_running_experiment(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Shared Advert Start');

        $productA = AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 900401,
            'vendor_code' => 'SHR-A',
            'title' => 'Shared A',
        ]);
        $productB = AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 900402,
            'vendor_code' => 'SHR-B',
            'title' => 'Shared B',
        ]);

        AbExperiment::query()->create([
            'ab_product_id' => $productA->id,
            'cabinet_id' => $cabinet->id,
            'name' => 'Already running on campaign',
            'status' => WbAbTestStatus::Running,
            'wb_advert_id' => 812001,
            'progress' => 10,
        ]);

        $draft = AbExperiment::query()->create([
            'ab_product_id' => $productB->id,
            'cabinet_id' => $cabinet->id,
            'name' => 'Wants same campaign',
            'status' => WbAbTestStatus::Draft,
            'wb_advert_id' => 812001,
            'progress' => 70,
        ]);

        $service = app(WbAbTestingService::class);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $service->assertCanStartExperiment($draft);
    }

    public function test_start_rejects_incomplete_draft(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Start Incomplete');
        [, $experiment] = $this->createDraftExperimentWithCampaign($cabinet);

        $this->actingAs($user)
            ->postJson("/panel/wb/ab-testing/experiments/{$experiment->id}/start")
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(WbAbTestStatus::Draft, $experiment->fresh()->status);
    }

    public function test_start_happy_path_sets_running_and_opens_cycle(): void
    {
        Storage::fake('private');

        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Start Happy');
        $cabinet->apikey = 'test-api-key';
        $cabinet->save();

        [$product, $experiment] = $this->createDraftExperimentWithCampaign($cabinet);
        $experiment->update([
            'impressions_per_photo' => 1000,
            'impressions_per_round' => 100,
            'round_minutes' => 60,
            'cpm' => 350,
            'progress' => 70,
        ]);

        Storage::disk('private')->put('wb/ab-testing/a.jpg', 'fake-image-a');
        Storage::disk('private')->put('wb/ab-testing/b.jpg', 'fake-image-b');

        AbExperimentPhoto::query()->create([
            'ab_experiment_id' => $experiment->id,
            'cabinet_id' => $cabinet->id,
            'sort_order' => 0,
            'disk' => 'private',
            'path' => 'wb/ab-testing/a.jpg',
            'original_name' => 'a.jpg',
            'mime' => 'image/jpeg',
            'size' => 10,
        ]);
        AbExperimentPhoto::query()->create([
            'ab_experiment_id' => $experiment->id,
            'cabinet_id' => $cabinet->id,
            'sort_order' => 1,
            'disk' => 'private',
            'path' => 'wb/ab-testing/b.jpg',
            'original_name' => 'b.jpg',
            'mime' => 'image/jpeg',
            'size' => 10,
        ]);

        $advertId = (int) $experiment->wb_advert_id;
        $nmId = (int) $product->nm_id;

        $advertApi = Mockery::mock(WbAdvertApiClient::class);
        $advertApi->shouldReceive('getAdverts')
            ->once()
            ->andReturn([
                'success' => true,
                'code' => 200,
                'data' => [
                    'adverts' => [
                        [
                            'id' => $advertId,
                            'status' => 4,
                            'nm_settings' => [['nm_id' => $nmId]],
                        ],
                    ],
                ],
            ]);
        $advertApi->shouldReceive('getBudget')
            ->once()
            ->andReturn([
                'success' => true,
                'code' => 200,
                'data' => ['total' => 1000, 'cash' => 1000],
            ]);
        $advertApi->shouldReceive('extractBudgetTotal')
            ->once()
            ->andReturn(1000.0);
        $advertApi->shouldReceive('startAdvert')
            ->once()
            ->andReturn(['success' => true, 'code' => 200, 'data' => null]);
        $advertApi->shouldReceive('fullstats')
            ->once()
            ->andReturn([
                'success' => true,
                'code' => 200,
                'rows' => [['advertId' => $advertId, 'views' => 10, 'clicks' => 1, 'sum' => 5, 'orders' => 0]],
            ]);
        $advertApi->shouldReceive('extractStatsForAdvert')
            ->once()
            ->andReturn(['views' => 10, 'clicks' => 1, 'spend' => 5.0, 'orders' => 0, 'ctr' => 10.0]);

        $mediaApi = Mockery::mock(\App\Services\Wb\WbContentMediaClient::class);
        $mediaApi->shouldReceive('uploadMediaFile')
            ->once()
            ->andReturn(['success' => true, 'code' => 200, 'data' => null]);

        $this->app->instance(WbAdvertApiClient::class, $advertApi);
        $this->app->instance(\App\Services\Wb\WbContentMediaClient::class, $mediaApi);

        \Illuminate\Support\Facades\Queue::fake();

        $this->actingAs($user)
            ->postJson("/panel/wb/ab-testing/experiments/{$experiment->id}/start")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('experiment.status', 'running')
            ->assertJsonPath('experiment.progress', 0)
            ->assertJsonPath('experiment.progress_mode', 'pending');

        \Illuminate\Support\Facades\Queue::assertPushed(
            \App\Jobs\Wb\AbTesting\ProcessAbExperimentJob::class,
            function ($job) use ($experiment) {
                return (int) $job->experimentId === (int) $experiment->id;
            },
        );

        $fresh = $experiment->fresh();
        $this->assertSame(WbAbTestStatus::Running, $fresh->status);
        $this->assertSame(0, (int) $fresh->progress);
        $this->assertNotNull($fresh->started_at);

        $openCycle = $fresh->resolveOpenCycle();
        $this->assertNotNull($openCycle);
        $this->assertNull($openCycle->ended_at);

        $this->assertDatabaseHas('wb_ab_experiment_cycles', [
            'ab_experiment_id' => $experiment->id,
            'sequence' => 1,
            'views_start' => 10,
        ]);
        $this->assertDatabaseHas('wb_ab_experiment_events', [
            'ab_experiment_id' => $experiment->id,
            'type' => 'experiment.started',
        ]);
    }

    public function test_start_rejects_zero_budget_with_clear_message(): void
    {
        Storage::fake('private');

        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Zero Budget');
        $cabinet->apikey = 'test-api-key';
        $cabinet->save();

        $product = AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 910099,
            'vendor_code' => 'ZERO-BUD',
            'title' => 'Zero budget product',
        ]);

        $experiment = AbExperiment::query()->create([
            'ab_product_id' => $product->id,
            'cabinet_id' => $cabinet->id,
            'name' => 'Zero budget draft',
            'status' => WbAbTestStatus::Draft,
            'wb_advert_id' => 800099,
            'wb_advert_name' => 'Zero budget campaign',
            'impressions_per_photo' => 1000,
            'impressions_per_round' => 100,
            'round_minutes' => 60,
            'cpm' => 350,
            'progress' => 70,
        ]);

        Storage::disk('private')->put('wb/ab-testing/z-a.jpg', 'fake-a');
        Storage::disk('private')->put('wb/ab-testing/z-b.jpg', 'fake-b');

        AbExperimentPhoto::query()->create([
            'ab_experiment_id' => $experiment->id,
            'cabinet_id' => $cabinet->id,
            'sort_order' => 0,
            'disk' => 'private',
            'path' => 'wb/ab-testing/z-a.jpg',
            'original_name' => 'a.jpg',
            'mime' => 'image/jpeg',
            'size' => 10,
        ]);
        AbExperimentPhoto::query()->create([
            'ab_experiment_id' => $experiment->id,
            'cabinet_id' => $cabinet->id,
            'sort_order' => 1,
            'disk' => 'private',
            'path' => 'wb/ab-testing/z-b.jpg',
            'original_name' => 'b.jpg',
            'mime' => 'image/jpeg',
            'size' => 10,
        ]);

        $advertId = (int) $experiment->wb_advert_id;
        $nmId = (int) $product->nm_id;

        $advertApi = Mockery::mock(WbAdvertApiClient::class);
        $advertApi->shouldReceive('getAdverts')
            ->once()
            ->andReturn([
                'success' => true,
                'code' => 200,
                'data' => [
                    'adverts' => [
                        [
                            'id' => $advertId,
                            'status' => 4,
                            'nm_settings' => [['nm_id' => $nmId]],
                        ],
                    ],
                ],
            ]);
        $advertApi->shouldReceive('getBudget')
            ->once()
            ->andReturn([
                'success' => true,
                'code' => 200,
                'data' => ['total' => 0, 'cash' => 0],
            ]);
        $advertApi->shouldReceive('extractBudgetTotal')
            ->once()
            ->andReturn(0.0);
        $advertApi->shouldNotReceive('startAdvert');

        $this->app->instance(WbAdvertApiClient::class, $advertApi);

        $response = $this->actingAs($user)
            ->postJson("/panel/wb/ab-testing/experiments/{$experiment->id}/start")
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $message = (string) ($response->json('messages.0') ?? '');
        $this->assertStringContainsString('нет бюджета', mb_strtolower($message));

        $this->assertSame(WbAbTestStatus::Draft, $experiment->fresh()->status);
    }

    public function test_create_campaign_with_budget_deposit_calls_api(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Deposit Campaign Cabinet');

        $product = AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 900010,
            'vendor_code' => 'DEP-SKU',
            'title' => 'Deposit product',
        ]);

        $experiment = AbExperiment::query()->create([
            'ab_product_id' => $product->id,
            'cabinet_id' => $cabinet->id,
            'name' => 'Deposit draft',
            'status' => WbAbTestStatus::Draft,
            'progress' => 0,
        ]);

        $client = Mockery::mock(WbAdvertApiClient::class);
        $client->shouldReceive('createSeacatCampaign')
            ->once()
            ->andReturn([
                'success' => true,
                'code' => 200,
                'advert_id' => 555777,
                'data' => 555777,
            ]);
        $client->shouldReceive('depositBudget')
            ->once()
            ->withArgs(function (string $apiKey, int $advertId, int $sum, int $type) {
                return $apiKey === 'test-api-key'
                    && $advertId === 555777
                    && $sum === 1000
                    && $type === WbAdvertApiClient::BUDGET_DEPOSIT_TYPE_BALANCE;
            })
            ->andReturn(['success' => true, 'code' => 200, 'data' => ['total' => 1000]]);

        $this->app->instance(WbAdvertApiClient::class, $client);

        $this->actingAs($user)
            ->postJson('/panel/wb/ab-testing/campaigns', [
                'experiment_id' => $experiment->id,
                'name' => 'A/B тест — DEP-SKU',
                'bid_type' => 'unified',
                'payment_type' => 'cpm',
                'budget_deposit' => 1000,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('budget_deposited', true)
            ->assertJsonPath('experiment.wb_advert_id', 555777);
    }

    public function test_create_campaign_deposit_fail_still_binds_with_flag(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Deposit Fail Cabinet');

        $product = AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 900011,
            'vendor_code' => 'DEP-FAIL',
            'title' => 'Deposit fail product',
        ]);

        $experiment = AbExperiment::query()->create([
            'ab_product_id' => $product->id,
            'cabinet_id' => $cabinet->id,
            'name' => 'Deposit fail draft',
            'status' => WbAbTestStatus::Draft,
            'progress' => 0,
        ]);

        $client = Mockery::mock(WbAdvertApiClient::class);
        $client->shouldReceive('createSeacatCampaign')
            ->once()
            ->andReturn([
                'success' => true,
                'code' => 200,
                'advert_id' => 555778,
                'data' => 555778,
            ]);
        $client->shouldReceive('depositBudget')
            ->once()
            ->andReturn([
                'success' => false,
                'code' => 400,
                'data' => null,
                'message' => 'Not enough money',
            ]);

        $this->app->instance(WbAdvertApiClient::class, $client);

        $this->actingAs($user)
            ->postJson('/panel/wb/ab-testing/campaigns', [
                'experiment_id' => $experiment->id,
                'name' => 'A/B тест — DEP-FAIL',
                'bid_type' => 'unified',
                'payment_type' => 'cpm',
                'budget_deposit' => 1000,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('budget_deposited', false)
            ->assertJsonPath('experiment.wb_advert_id', 555778);

        $this->assertDatabaseHas('wb_ab_experiments', [
            'id' => $experiment->id,
            'wb_advert_id' => 555778,
        ]);
    }

    public function test_pause_campaign_when_active(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Pause Campaign');

        AbCampaign::query()->create([
            'cabinet_id' => $cabinet->id,
            'wb_advert_id' => 333001,
            'name' => 'Active campaign',
            'bid_type' => 'unified',
            'payment_type' => 'cpm',
        ]);

        $client = Mockery::mock(WbAdvertApiClient::class);
        $client->shouldReceive('getAdverts')
            ->twice()
            ->andReturn(
                [
                    'success' => true,
                    'code' => 200,
                    'data' => [
                        'adverts' => [
                            ['id' => 333001, 'status' => 9, 'settings' => ['name' => 'Active campaign']],
                        ],
                    ],
                ],
                [
                    'success' => true,
                    'code' => 200,
                    'data' => [
                        'adverts' => [
                            ['id' => 333001, 'status' => 11, 'settings' => ['name' => 'Active campaign']],
                        ],
                    ],
                ],
            );
        $client->shouldReceive('pauseAdvert')
            ->once()
            ->with('test-api-key', 333001)
            ->andReturn(['success' => true, 'code' => 200, 'data' => null]);

        $this->app->instance(WbAdvertApiClient::class, $client);

        $this->actingAs($user)
            ->postJson('/panel/wb/ab-testing/campaigns/333001/pause')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('campaign.status', 11);
    }

    public function test_deposit_existing_campaign_budget(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Deposit Existing');

        AbCampaign::query()->create([
            'cabinet_id' => $cabinet->id,
            'wb_advert_id' => 333002,
            'name' => 'Depositable',
            'bid_type' => 'unified',
            'payment_type' => 'cpm',
        ]);

        $client = Mockery::mock(WbAdvertApiClient::class);
        $client->shouldReceive('getAdverts')
            ->once()
            ->andReturn([
                'success' => true,
                'code' => 200,
                'data' => [
                    'adverts' => [
                        ['id' => 333002, 'status' => 4, 'settings' => ['name' => 'Depositable']],
                    ],
                ],
            ]);
        $client->shouldReceive('depositBudget')
            ->once()
            ->withArgs(function (string $apiKey, int $advertId, int $sum, int $type) {
                return $apiKey === 'test-api-key'
                    && $advertId === 333002
                    && $sum === 1500
                    && $type === WbAdvertApiClient::BUDGET_DEPOSIT_TYPE_BALANCE;
            })
            ->andReturn(['success' => true, 'code' => 200, 'data' => ['total' => 1500]]);
        $client->shouldReceive('extractBudgetTotal')
            ->once()
            ->with(['total' => 1500])
            ->andReturn(1500.0);

        $this->app->instance(WbAdvertApiClient::class, $client);

        $this->actingAs($user)
            ->postJson('/panel/wb/ab-testing/campaigns/333002/deposit', ['sum' => 1500])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('budget_total', 1500)
            ->assertJsonPath('deposited_sum', 1500);
    }

    public function test_get_campaign_budget(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Get Budget');

        AbCampaign::query()->create([
            'cabinet_id' => $cabinet->id,
            'wb_advert_id' => 333004,
            'name' => 'Budget read',
            'bid_type' => 'unified',
            'payment_type' => 'cpm',
        ]);

        $client = Mockery::mock(WbAdvertApiClient::class);
        $client->shouldReceive('getBudget')
            ->once()
            ->with('test-api-key', 333004)
            ->andReturn([
                'success' => true,
                'code' => 200,
                'data' => ['total' => 2500, 'cash' => 2500],
            ]);
        $client->shouldReceive('extractBudgetTotal')
            ->once()
            ->andReturn(2500.0);

        $this->app->instance(WbAdvertApiClient::class, $client);

        $this->actingAs($user)
            ->getJson('/panel/wb/ab-testing/campaigns/333004/budget')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('budget_total', 2500);
    }

    public function test_delete_campaign_removes_registry_and_unbinds_draft(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Delete Campaign');

        $product = AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 900020,
            'vendor_code' => 'DEL-SKU',
            'title' => 'Delete product',
        ]);

        $experiment = AbExperiment::query()->create([
            'ab_product_id' => $product->id,
            'cabinet_id' => $cabinet->id,
            'name' => 'Bound draft',
            'status' => WbAbTestStatus::Draft,
            'wb_advert_id' => 333003,
            'wb_advert_name' => 'To delete',
            'progress' => 30,
        ]);

        AbCampaign::query()->create([
            'cabinet_id' => $cabinet->id,
            'wb_advert_id' => 333003,
            'name' => 'To delete',
            'bid_type' => 'unified',
            'payment_type' => 'cpm',
            'created_by_experiment_id' => $experiment->id,
        ]);

        $client = Mockery::mock(WbAdvertApiClient::class);
        $client->shouldReceive('getAdverts')
            ->once()
            ->andReturn([
                'success' => true,
                'code' => 200,
                'data' => [
                    'adverts' => [
                        ['id' => 333003, 'status' => 11, 'settings' => ['name' => 'To delete']],
                    ],
                ],
            ]);
        $client->shouldReceive('deleteAdvert')
            ->once()
            ->with('test-api-key', 333003)
            ->andReturn(['success' => true, 'code' => 200, 'data' => null]);

        $this->app->instance(WbAdvertApiClient::class, $client);

        $this->actingAs($user)
            ->deleteJson('/panel/wb/ab-testing/campaigns/333003?experiment_id='.$experiment->id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('experiment.wb_advert_id', null);

        $this->assertDatabaseMissing('wb_ab_campaigns', [
            'cabinet_id' => $cabinet->id,
            'wb_advert_id' => 333003,
        ]);

        $this->assertDatabaseHas('wb_ab_experiments', [
            'id' => $experiment->id,
            'wb_advert_id' => null,
        ]);
    }

    public function test_can_restart_stopped_experiment(): void
    {
        Storage::fake('private');

        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Restart Exp');
        $cabinet->apikey = 'test-api-key';
        $cabinet->save();

        $product = AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 940001,
            'vendor_code' => 'RESTART',
            'title' => 'Restart product',
        ]);

        $experiment = AbExperiment::query()->create([
            'ab_product_id' => $product->id,
            'cabinet_id' => $cabinet->id,
            'name' => 'Stopped exp',
            'status' => WbAbTestStatus::Stopped,
            'progress' => 40,
            'wb_advert_id' => 840001,
            'wb_advert_name' => 'Camp',
            'started_at' => now()->subDay(),
            'finished_at' => now()->subHour(),
            'impressions_per_photo' => 1000,
            'impressions_per_round' => 100,
            'round_minutes' => 60,
            'cpm' => 350,
        ]);

        Storage::disk('private')->put('wb/ab-testing/r-a.jpg', 'a');
        Storage::disk('private')->put('wb/ab-testing/r-b.jpg', 'b');
        AbExperimentPhoto::query()->create([
            'ab_experiment_id' => $experiment->id,
            'cabinet_id' => $cabinet->id,
            'sort_order' => 0,
            'disk' => 'private',
            'path' => 'wb/ab-testing/r-a.jpg',
            'mime' => 'image/jpeg',
            'size' => 1,
        ]);
        AbExperimentPhoto::query()->create([
            'ab_experiment_id' => $experiment->id,
            'cabinet_id' => $cabinet->id,
            'sort_order' => 1,
            'disk' => 'private',
            'path' => 'wb/ab-testing/r-b.jpg',
            'mime' => 'image/jpeg',
            'size' => 1,
        ]);

        \App\Models\Subscribers\Wb\AbTesting\AbExperimentCycle::query()->create([
            'ab_experiment_id' => $experiment->id,
            'cabinet_id' => $cabinet->id,
            'ab_experiment_photo_id' => AbExperimentPhoto::query()->where('ab_experiment_id', $experiment->id)->value('id'),
            'sequence' => 3,
            'started_at' => now()->subHours(2),
            'ended_at' => now()->subHour(),
            'end_reason' => 'experiment_stopped',
            'views_start' => 0,
            'views_end' => 50,
            'clicks_start' => 0,
            'clicks_end' => 2,
            'spend_start' => 0,
            'spend_end' => 1,
            'orders_start' => 0,
            'orders_end' => 0,
        ]);

        $advertApi = Mockery::mock(WbAdvertApiClient::class);
        $advertApi->shouldReceive('getAdverts')->once()->andReturn([
            'success' => true,
            'code' => 200,
            'data' => [
                'adverts' => [[
                    'id' => 840001,
                    'status' => 11,
                    'nm_settings' => [['nm_id' => 940001]],
                ]],
            ],
        ]);
        $advertApi->shouldReceive('getBudget')->once()->andReturn([
            'success' => true,
            'code' => 200,
            'data' => ['total' => 5000],
        ]);
        $advertApi->shouldReceive('extractBudgetTotal')->once()->andReturn(5000.0);
        $advertApi->shouldReceive('startAdvert')->once()->andReturn(['success' => true, 'code' => 200]);
        $advertApi->shouldReceive('fullstats')->once()->andReturn([
            'success' => true,
            'code' => 200,
            'rows' => [['advertId' => 840001, 'views' => 100, 'clicks' => 3, 'sum' => 1, 'orders' => 0]],
        ]);
        $advertApi->shouldReceive('extractStatsForAdvert')->once()->andReturn([
            'views' => 100, 'clicks' => 3, 'spend' => 1.0, 'orders' => 0, 'ctr' => 3.0,
        ]);

        $mediaApi = Mockery::mock(\App\Services\Wb\WbContentMediaClient::class);
        $mediaApi->shouldReceive('uploadMediaFile')->once()->andReturn(['success' => true, 'code' => 200]);

        $this->app->instance(WbAdvertApiClient::class, $advertApi);
        $this->app->instance(\App\Services\Wb\WbContentMediaClient::class, $mediaApi);
        \Illuminate\Support\Facades\Queue::fake();

        $this->actingAs($user)
            ->postJson("/panel/wb/ab-testing/experiments/{$experiment->id}/start")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('experiment.status', 'running');

        $this->assertDatabaseHas('wb_ab_experiment_cycles', [
            'ab_experiment_id' => $experiment->id,
            'sequence' => 4,
        ]);
        $this->assertNull($experiment->fresh()->finished_at);
    }

    public function test_stopped_experiment_can_update_settings(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Stopped Settings');

        $product = AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 940002,
            'vendor_code' => 'ST-SET',
            'title' => 'Stopped settings',
        ]);

        $experiment = AbExperiment::query()->create([
            'ab_product_id' => $product->id,
            'cabinet_id' => $cabinet->id,
            'name' => 'Stopped editable',
            'status' => WbAbTestStatus::Stopped,
            'progress' => 20,
            'impressions_per_photo' => 1000,
            'impressions_per_round' => 100,
            'round_minutes' => 60,
            'cpm' => 350,
        ]);

        $this->actingAs($user)
            ->patchJson("/panel/wb/ab-testing/experiments/{$experiment->id}/settings", [
                'impressions_per_photo' => 2000,
                'impressions_per_round' => 200,
                'round_minutes' => 30,
                'cpm' => 400,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('experiment.can_edit', true)
            ->assertJsonPath('experiment.settings.impressions_per_photo', 2000);
    }

    public function test_stop_running_experiment(): void
    {
        Storage::fake('private');

        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Stop Exp');
        $cabinet->apikey = 'test-api-key';
        $cabinet->save();

        $product = AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 910001,
            'vendor_code' => 'STOP',
            'title' => 'Stop product',
        ]);

        $experiment = AbExperiment::query()->create([
            'ab_product_id' => $product->id,
            'cabinet_id' => $cabinet->id,
            'name' => 'Running to stop',
            'status' => WbAbTestStatus::Running,
            'progress' => 85,
            'wb_advert_id' => 800001,
            'started_at' => now()->subHour(),
            'impressions_per_photo' => 1000,
            'impressions_per_round' => 100,
            'round_minutes' => 60,
            'cpm' => 350,
        ]);

        Storage::disk('private')->put('wb/ab-testing/s.jpg', 'x');
        $photo = AbExperimentPhoto::query()->create([
            'ab_experiment_id' => $experiment->id,
            'cabinet_id' => $cabinet->id,
            'sort_order' => 0,
            'disk' => 'private',
            'path' => 'wb/ab-testing/s.jpg',
            'mime' => 'image/jpeg',
            'size' => 1,
        ]);

        $cycle = \App\Models\Subscribers\Wb\AbTesting\AbExperimentCycle::query()->create([
            'ab_experiment_id' => $experiment->id,
            'cabinet_id' => $cabinet->id,
            'ab_experiment_photo_id' => $photo->id,
            'sequence' => 1,
            'started_at' => now()->subMinutes(30),
            'views_start' => 0,
            'clicks_start' => 0,
            'spend_start' => 0,
            'orders_start' => 0,
        ]);
        $advertApi = Mockery::mock(WbAdvertApiClient::class);
        $advertApi->shouldReceive('fullstats')->andReturn([
            'success' => true,
            'code' => 200,
            'rows' => [['advertId' => 800001, 'views' => 50, 'clicks' => 2, 'sum' => 1, 'orders' => 0]],
        ]);
        $advertApi->shouldReceive('extractStatsForAdvert')->andReturn([
            'views' => 50, 'clicks' => 2, 'spend' => 1.0, 'orders' => 0, 'ctr' => 4.0,
        ]);
        $advertApi->shouldReceive('pauseAdvert')->once()->andReturn(['success' => true, 'code' => 200]);

        $this->app->instance(WbAdvertApiClient::class, $advertApi);
        $this->app->instance(
            \App\Services\Wb\WbContentMediaClient::class,
            Mockery::mock(\App\Services\Wb\WbContentMediaClient::class),
        );

        $this->actingAs($user)
            ->postJson("/panel/wb/ab-testing/experiments/{$experiment->id}/stop")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('experiment.status', 'stopped');

        $fresh = $experiment->fresh();
        $this->assertSame(WbAbTestStatus::Stopped, $fresh->status);
        $this->assertNotNull($cycle->fresh()->ended_at);
        $this->assertSame('experiment_stopped', $cycle->fresh()->end_reason);
    }

    public function test_engine_process_switches_photo_on_impressions_limit(): void
    {
        Storage::fake('private');

        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Tick Switch');
        $cabinet->apikey = 'test-api-key';
        $cabinet->save();

        $product = AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 920001,
            'vendor_code' => 'TICK',
            'title' => 'Tick product',
        ]);

        $experiment = AbExperiment::query()->create([
            'ab_product_id' => $product->id,
            'cabinet_id' => $cabinet->id,
            'name' => 'Tick exp',
            'status' => WbAbTestStatus::Running,
            'progress' => 80,
            'wb_advert_id' => 810001,
            'started_at' => now()->subHours(2),
            'impressions_per_photo' => 10000,
            'impressions_per_round' => 100,
            'round_minutes' => 600,
            'cpm' => 350,
        ]);

        Storage::disk('private')->put('wb/ab-testing/t1.jpg', '1');
        Storage::disk('private')->put('wb/ab-testing/t2.jpg', '2');
        $photo1 = AbExperimentPhoto::query()->create([
            'ab_experiment_id' => $experiment->id,
            'cabinet_id' => $cabinet->id,
            'sort_order' => 0,
            'disk' => 'private',
            'path' => 'wb/ab-testing/t1.jpg',
            'mime' => 'image/jpeg',
            'size' => 1,
        ]);
        $photo2 = AbExperimentPhoto::query()->create([
            'ab_experiment_id' => $experiment->id,
            'cabinet_id' => $cabinet->id,
            'sort_order' => 1,
            'disk' => 'private',
            'path' => 'wb/ab-testing/t2.jpg',
            'mime' => 'image/jpeg',
            'size' => 1,
        ]);

        $cycle = \App\Models\Subscribers\Wb\AbTesting\AbExperimentCycle::query()->create([
            'ab_experiment_id' => $experiment->id,
            'cabinet_id' => $cabinet->id,
            'ab_experiment_photo_id' => $photo1->id,
            'sequence' => 1,
            'started_at' => now()->subMinutes(10),
            'views_start' => 0,
            'clicks_start' => 0,
            'spend_start' => 0,
            'orders_start' => 0,
        ]);
        $advertApi = Mockery::mock(WbAdvertApiClient::class);
        $advertApi->shouldReceive('fullstats')->andReturn([
            'success' => true,
            'code' => 200,
            'rows' => [['advertId' => 810001, 'views' => 150, 'clicks' => 5, 'sum' => 2, 'orders' => 0]],
        ]);
        $advertApi->shouldReceive('extractStatsForAdvert')->andReturn([
            'views' => 150, 'clicks' => 5, 'spend' => 2.0, 'orders' => 0, 'ctr' => 3.3,
        ]);

        $mediaApi = Mockery::mock(\App\Services\Wb\WbContentMediaClient::class);
        $mediaApi->shouldReceive('uploadMediaFile')
            ->once()
            ->withArgs(function ($key, $nm, $photoNumber) use ($product) {
                return $nm === (int) $product->nm_id && $photoNumber === 1;
            })
            ->andReturn(['success' => true, 'code' => 200]);

        $this->app->instance(WbAdvertApiClient::class, $advertApi);
        $this->app->instance(\App\Services\Wb\WbContentMediaClient::class, $mediaApi);

        $engine = app(\App\Services\Subscriber\Wb\AbTesting\WbAbExperimentEngine::class);
        $result = $engine->process($experiment->fresh(['photos', 'product', 'cabinet']));

        $this->assertTrue($result['success']);
        $this->assertSame('switched', $result['action']);

        $this->assertNotNull($cycle->fresh()->ended_at);
        $this->assertSame('impressions_limit', $cycle->fresh()->end_reason);

        $experiment->refresh();
        $open = $experiment->resolveOpenCycle();
        $this->assertNotNull($open);
        $this->assertSame((int) $photo2->id, (int) $open->ab_experiment_photo_id);
        // Bottleneck photo2 still at 0 views → progress 0 (not the old 80 floor).
        $this->assertSame(0, (int) $experiment->progress);
        $this->assertDatabaseHas('wb_ab_experiment_cycles', [
            'ab_experiment_id' => $experiment->id,
            'sequence' => 2,
            'ab_experiment_photo_id' => $photo2->id,
        ]);
    }

    public function test_engine_complete_applies_winner_as_main_photo(): void
    {
        Storage::fake('private');

        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Winner Apply');
        $cabinet->apikey = 'test-api-key';
        $cabinet->save();

        $product = AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 921001,
            'vendor_code' => 'WIN',
            'title' => 'Winner product',
        ]);

        $experiment = AbExperiment::query()->create([
            'ab_product_id' => $product->id,
            'cabinet_id' => $cabinet->id,
            'name' => 'Winner exp',
            'status' => WbAbTestStatus::Running,
            'progress' => 99,
            'wb_advert_id' => 811001,
            'started_at' => now()->subHours(5),
            'impressions_per_photo' => 100,
            'impressions_per_round' => 1000,
            'round_minutes' => 600,
            'cpm' => 350,
        ]);

        Storage::disk('private')->put('wb/ab-testing/w1.jpg', 'winner-bytes');
        Storage::disk('private')->put('wb/ab-testing/w2.jpg', 'loser-bytes');
        $photo1 = AbExperimentPhoto::query()->create([
            'ab_experiment_id' => $experiment->id,
            'cabinet_id' => $cabinet->id,
            'sort_order' => 0,
            'disk' => 'private',
            'path' => 'wb/ab-testing/w1.jpg',
            'original_name' => 'w1.jpg',
            'mime' => 'image/jpeg',
            'size' => 12,
        ]);
        $photo2 = AbExperimentPhoto::query()->create([
            'ab_experiment_id' => $experiment->id,
            'cabinet_id' => $cabinet->id,
            'sort_order' => 1,
            'disk' => 'private',
            'path' => 'wb/ab-testing/w2.jpg',
            'original_name' => 'w2.jpg',
            'mime' => 'image/jpeg',
            'size' => 11,
        ]);

        // photo1 closed: 100 views, 20 clicks → CTR 20%
        \App\Models\Subscribers\Wb\AbTesting\AbExperimentCycle::query()->create([
            'ab_experiment_id' => $experiment->id,
            'cabinet_id' => $cabinet->id,
            'ab_experiment_photo_id' => $photo1->id,
            'sequence' => 1,
            'started_at' => now()->subHours(3),
            'ended_at' => now()->subHours(2),
            'end_reason' => 'impressions_limit',
            'views_start' => 0,
            'views_end' => 100,
            'clicks_start' => 0,
            'clicks_end' => 20,
            'spend_start' => 0,
            'spend_end' => 1,
            'orders_start' => 0,
            'orders_end' => 0,
        ]);
        // photo2 open: will reach 100 views via snapshot; lower CTR
        \App\Models\Subscribers\Wb\AbTesting\AbExperimentCycle::query()->create([
            'ab_experiment_id' => $experiment->id,
            'cabinet_id' => $cabinet->id,
            'ab_experiment_photo_id' => $photo2->id,
            'sequence' => 2,
            'started_at' => now()->subHour(),
            'views_start' => 100,
            'clicks_start' => 20,
            'spend_start' => 1,
            'orders_start' => 0,
        ]);

        $advertApi = Mockery::mock(WbAdvertApiClient::class);
        $advertApi->shouldReceive('fullstats')->andReturn([
            'success' => true,
            'code' => 200,
            // Cumulative: +100 views / +5 clicks on photo2 cycle → CTR 5%
            'rows' => [['advertId' => 811001, 'views' => 200, 'clicks' => 25, 'sum' => 3, 'orders' => 0]],
        ]);
        $advertApi->shouldReceive('extractStatsForAdvert')->andReturn([
            'views' => 200, 'clicks' => 25, 'spend' => 3.0, 'orders' => 0, 'ctr' => 12.5,
        ]);
        $advertApi->shouldReceive('pauseAdvert')->once()->andReturn(['success' => true, 'code' => 200]);

        $mediaApi = Mockery::mock(\App\Services\Wb\WbContentMediaClient::class);
        $mediaApi->shouldReceive('uploadMediaFile')
            ->once()
            ->withArgs(function ($key, $nm, $photoNumber, $binary, $filename) use ($product) {
                return $nm === (int) $product->nm_id
                    && $photoNumber === 1
                    && $binary === 'winner-bytes'
                    && $filename === 'w1.jpg';
            })
            ->andReturn(['success' => true, 'code' => 200]);

        $this->app->instance(WbAdvertApiClient::class, $advertApi);
        $this->app->instance(\App\Services\Wb\WbContentMediaClient::class, $mediaApi);

        $engine = app(\App\Services\Subscriber\Wb\AbTesting\WbAbExperimentEngine::class);
        $result = $engine->process($experiment->fresh(['photos', 'product', 'cabinet']));

        $this->assertTrue($result['success']);
        $this->assertSame('completed', $result['action']);

        $fresh = $experiment->fresh();
        $this->assertSame(WbAbTestStatus::Completed, $fresh->status);
        $this->assertSame((int) $photo1->id, (int) $fresh->winner_photo_id);
        $this->assertNull($fresh->error_message);
    }

    public function test_impressions_progress_bottleneck_formula(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Progress Formula');

        $product = AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 930001,
            'vendor_code' => 'PROG',
            'title' => 'Progress product',
        ]);

        $experiment = AbExperiment::query()->create([
            'ab_product_id' => $product->id,
            'cabinet_id' => $cabinet->id,
            'name' => 'Progress exp',
            'status' => WbAbTestStatus::Running,
            'progress' => 0,
            'wb_advert_id' => 820001,
            'started_at' => now()->subHour(),
            'impressions_per_photo' => 1000,
            'impressions_per_round' => 100,
            'round_minutes' => 60,
            'cpm' => 350,
        ]);

        $photo1 = AbExperimentPhoto::query()->create([
            'ab_experiment_id' => $experiment->id,
            'cabinet_id' => $cabinet->id,
            'sort_order' => 0,
            'disk' => 'private',
            'path' => 'wb/ab-testing/p1.jpg',
            'mime' => 'image/jpeg',
            'size' => 1,
        ]);
        $photo2 = AbExperimentPhoto::query()->create([
            'ab_experiment_id' => $experiment->id,
            'cabinet_id' => $cabinet->id,
            'sort_order' => 1,
            'disk' => 'private',
            'path' => 'wb/ab-testing/p2.jpg',
            'mime' => 'image/jpeg',
            'size' => 1,
        ]);

        // Closed cycle: photo1 got 500 views (50% of 1000).
        \App\Models\Subscribers\Wb\AbTesting\AbExperimentCycle::query()->create([
            'ab_experiment_id' => $experiment->id,
            'cabinet_id' => $cabinet->id,
            'ab_experiment_photo_id' => $photo1->id,
            'sequence' => 1,
            'started_at' => now()->subMinutes(40),
            'ended_at' => now()->subMinutes(20),
            'end_reason' => 'impressions_limit',
            'views_start' => 0,
            'views_end' => 500,
            'clicks_start' => 0,
            'clicks_end' => 10,
            'spend_start' => 0,
            'spend_end' => 1,
            'orders_start' => 0,
            'orders_end' => 0,
        ]);
        // Open cycle on photo2 with no extra snapshot → 0 views so far.
        \App\Models\Subscribers\Wb\AbTesting\AbExperimentCycle::query()->create([
            'ab_experiment_id' => $experiment->id,
            'cabinet_id' => $cabinet->id,
            'ab_experiment_photo_id' => $photo2->id,
            'sequence' => 2,
            'started_at' => now()->subMinutes(20),
            'views_start' => 500,
            'clicks_start' => 10,
            'spend_start' => 1,
            'orders_start' => 0,
        ]);

        $engine = app(\App\Services\Subscriber\Wb\AbTesting\WbAbExperimentEngine::class);
        $breakdown = $engine->impressionsProgressBreakdown($experiment->fresh(['photos']), 1000);

        // Bottleneck is photo2 at 0 → progress 0, mode pending (total views from closed = 500 though!)
        // total_views includes closed cycle 500, so mode should be views, progress still 0.
        $this->assertSame(500, $breakdown['total_views']);
        $this->assertSame('views', $breakdown['mode']);
        $this->assertSame(0, $breakdown['progress']);

        // If photo2 also had 500 via closed cycle, progress would be 50.
        \App\Models\Subscribers\Wb\AbTesting\AbExperimentCycle::query()
            ->where('ab_experiment_id', $experiment->id)
            ->where('sequence', 2)
            ->update([
                'ended_at' => now()->subMinutes(5),
                'end_reason' => 'impressions_limit',
                'views_end' => 1000,
                'clicks_end' => 20,
                'spend_end' => 2,
                'orders_end' => 0,
            ]);
        // Open cycle photo1 again with 0 extra
        \App\Models\Subscribers\Wb\AbTesting\AbExperimentCycle::query()->create([
            'ab_experiment_id' => $experiment->id,
            'cabinet_id' => $cabinet->id,
            'ab_experiment_photo_id' => $photo1->id,
            'sequence' => 3,
            'started_at' => now()->subMinutes(5),
            'views_start' => 1000,
            'clicks_start' => 20,
            'spend_start' => 2,
            'orders_start' => 0,
        ]);

        $breakdown2 = $engine->impressionsProgressBreakdown($experiment->fresh(['photos']), 1000);
        // photo1: 500, photo2: 500 → min 50%
        $this->assertSame(50, $breakdown2['progress']);
        $this->assertSame('views', $breakdown2['mode']);
    }

    public function test_photo_aggregates_include_open_cycle_with_provisional_ends(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Agg Provisional');

        $product = AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 940001,
            'vendor_code' => 'AGG',
            'title' => 'Agg product',
        ]);

        $experiment = AbExperiment::query()->create([
            'ab_product_id' => $product->id,
            'cabinet_id' => $cabinet->id,
            'name' => 'Agg exp',
            'status' => WbAbTestStatus::Running,
            'progress' => 0,
            'wb_advert_id' => 830001,
            'started_at' => now()->subHour(),
            'impressions_per_photo' => 1000,
            'impressions_per_round' => 100,
            'round_minutes' => 60,
            'cpm' => 350,
        ]);

        $photo1 = AbExperimentPhoto::query()->create([
            'ab_experiment_id' => $experiment->id,
            'cabinet_id' => $cabinet->id,
            'sort_order' => 0,
            'disk' => 'private',
            'path' => 'wb/ab-testing/agg1.jpg',
            'mime' => 'image/jpeg',
            'size' => 1,
        ]);

        // Open cycle with provisional ends (mid-flight).
        \App\Models\Subscribers\Wb\AbTesting\AbExperimentCycle::query()->create([
            'ab_experiment_id' => $experiment->id,
            'cabinet_id' => $cabinet->id,
            'ab_experiment_photo_id' => $photo1->id,
            'sequence' => 1,
            'started_at' => now()->subMinutes(10),
            'ended_at' => null,
            'views_start' => 100,
            'views_end' => 150,
            'clicks_start' => 5,
            'clicks_end' => 8,
            'spend_start' => 0,
            'spend_end' => 1,
            'orders_start' => 0,
            'orders_end' => 0,
        ]);

        $engine = app(\App\Services\Subscriber\Wb\AbTesting\WbAbExperimentEngine::class);
        $agg = $engine->photoAggregates($experiment->fresh(['photos']));

        $this->assertArrayHasKey($photo1->id, $agg);
        $this->assertSame(50, $agg[$photo1->id]['views']);
        $this->assertSame(3, $agg[$photo1->id]['clicks']);
        $this->assertEqualsWithDelta(6.0, (float) $agg[$photo1->id]['ctr'], 0.01);
    }

    public function test_photo_aggregates_subtract_campaign_baseline(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Baseline Agg');

        $product = AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 940101,
            'vendor_code' => 'BASE',
            'title' => 'Baseline product',
        ]);

        $experiment = AbExperiment::query()->create([
            'ab_product_id' => $product->id,
            'cabinet_id' => $cabinet->id,
            'name' => 'Baseline exp',
            'status' => WbAbTestStatus::Running,
            'progress' => 0,
            'wb_advert_id' => 830101,
            'started_at' => now()->subHour(),
            'impressions_per_photo' => 10000,
            'impressions_per_round' => 1000,
            'round_minutes' => 60,
            'cpm' => 350,
        ]);

        $photo1 = AbExperimentPhoto::query()->create([
            'ab_experiment_id' => $experiment->id,
            'cabinet_id' => $cabinet->id,
            'sort_order' => 0,
            'disk' => 'private',
            'path' => 'wb/ab-testing/base1.jpg',
            'mime' => 'image/jpeg',
            'size' => 1,
        ]);

        \App\Models\Subscribers\Wb\AbTesting\AbExperimentCycle::query()->create([
            'ab_experiment_id' => $experiment->id,
            'cabinet_id' => $cabinet->id,
            'ab_experiment_photo_id' => $photo1->id,
            'sequence' => 1,
            'started_at' => now()->subMinutes(10),
            'ended_at' => now(),
            'end_reason' => 'impressions_limit',
            'views_start' => 500000,
            'views_end' => 505000,
            'clicks_start' => 12000,
            'clicks_end' => 12150,
            'spend_start' => 100,
            'spend_end' => 110,
            'orders_start' => 0,
            'orders_end' => 0,
        ]);

        $engine = app(\App\Services\Subscriber\Wb\AbTesting\WbAbExperimentEngine::class);
        $agg = $engine->photoAggregates($experiment->fresh(['photos']));

        $this->assertSame(5000, $agg[$photo1->id]['views']);
        $this->assertSame(150, $agg[$photo1->id]['clicks']);
        $this->assertEqualsWithDelta(3.0, (float) $agg[$photo1->id]['ctr'], 0.01);
    }

    public function test_extract_stats_for_advert_uses_nm_breakdown_not_campaign_totals(): void
    {
        $client = new WbAdvertApiClient;

        $rows = [
            [
                'advertId' => 1,
                'views' => 500000,
                'clicks' => 12000,
                'sum' => 100,
                'days' => [
                    [
                        'apps' => [
                            [
                                'nms' => [
                                    ['nmId' => 111, 'views' => 400000, 'clicks' => 10000, 'sum' => 80, 'orders' => 2],
                                    ['nmId' => 222, 'views' => 100000, 'clicks' => 2000, 'sum' => 20, 'orders' => 1],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $forNm = $client->extractStatsForAdvert($rows, 1, 222);
        $this->assertSame(100000, $forNm['views']);
        $this->assertSame(2000, $forNm['clicks']);

        $missingNm = $client->extractStatsForAdvert($rows, 1, 999);
        $this->assertSame(0, $missingNm['views']);
        $this->assertSame(0, $missingNm['clicks']);

        $noBreakdown = $client->extractStatsForAdvert([
            ['advertId' => 1, 'views' => 50, 'clicks' => 2, 'sum' => 1, 'orders' => 0],
        ], 1, 222);
        $this->assertSame(50, $noBreakdown['views']);
        $this->assertSame(2, $noBreakdown['clicks']);
    }

    public function test_map_experiment_exposes_photo_stats_and_action_history(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Map History');

        $product = AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 950001,
            'vendor_code' => 'MAP',
            'title' => 'Map product',
        ]);

        $experiment = AbExperiment::query()->create([
            'ab_product_id' => $product->id,
            'cabinet_id' => $cabinet->id,
            'name' => 'Map exp',
            'status' => WbAbTestStatus::Running,
            'progress' => 10,
            'wb_advert_id' => 840001,
            'started_at' => now()->subHour(),
            'impressions_per_photo' => 1000,
            'impressions_per_round' => 100,
            'round_minutes' => 60,
            'cpm' => 350,
        ]);

        $photo1 = AbExperimentPhoto::query()->create([
            'ab_experiment_id' => $experiment->id,
            'cabinet_id' => $cabinet->id,
            'sort_order' => 0,
            'disk' => 'private',
            'path' => 'wb/ab-testing/map1.jpg',
            'mime' => 'image/jpeg',
            'size' => 1,
        ]);
        $photo2 = AbExperimentPhoto::query()->create([
            'ab_experiment_id' => $experiment->id,
            'cabinet_id' => $cabinet->id,
            'sort_order' => 1,
            'disk' => 'private',
            'path' => 'wb/ab-testing/map2.jpg',
            'mime' => 'image/jpeg',
            'size' => 1,
        ]);

        \App\Models\Subscribers\Wb\AbTesting\AbExperimentCycle::query()->create([
            'ab_experiment_id' => $experiment->id,
            'cabinet_id' => $cabinet->id,
            'ab_experiment_photo_id' => $photo1->id,
            'sequence' => 1,
            'started_at' => now()->subMinutes(40),
            'ended_at' => now()->subMinutes(20),
            'end_reason' => 'impressions_limit',
            'views_start' => 0,
            'views_end' => 200,
            'clicks_start' => 0,
            'clicks_end' => 10,
            'spend_start' => 0,
            'spend_end' => 1,
            'orders_start' => 0,
            'orders_end' => 0,
        ]);
        \App\Models\Subscribers\Wb\AbTesting\AbExperimentCycle::query()->create([
            'ab_experiment_id' => $experiment->id,
            'cabinet_id' => $cabinet->id,
            'ab_experiment_photo_id' => $photo2->id,
            'sequence' => 2,
            'started_at' => now()->subMinutes(20),
            'ended_at' => null,
            'views_start' => 200,
            'views_end' => 250,
            'clicks_start' => 10,
            'clicks_end' => 15,
            'spend_start' => 1,
            'spend_end' => 2,
            'orders_start' => 0,
            'orders_end' => 0,
        ]);

        $experiment->load([
            'photos',
            'cycles' => fn ($q) => $q->with('photo')->orderByDesc('sequence')->limit(100),
        ]);

        $service = app(\App\Services\Subscriber\Wb\WbAbTestingService::class);
        $mapped = $service->mapExperiment($experiment);

        $photosById = collect($mapped['photos'])->keyBy('id');
        $this->assertSame(200, $photosById[$photo1->id]['stats']['impressions']);
        $this->assertSame(10, $photosById[$photo1->id]['stats']['clicks']);
        $this->assertNotNull($photosById[$photo1->id]['stats']['ctr']);
        $this->assertSame(50, $photosById[$photo2->id]['stats']['impressions']);
        // Mid-flight: no efficiency % and no winner badge.
        $this->assertNull($photosById[$photo1->id]['stats']['result_delta_pct']);
        $this->assertNull($photosById[$photo2->id]['stats']['result_delta_pct']);
        $this->assertFalse($photosById[$photo1->id]['is_winner']);
        $this->assertFalse($photosById[$photo2->id]['is_winner']);

        $this->assertArrayHasKey('action_history', $mapped);
        $this->assertCount(2, $mapped['action_history']);
        $this->assertTrue($mapped['action_history'][0]['in_progress']);
        $this->assertSame(2, $mapped['action_history'][0]['round']);
        $this->assertSame(50, $mapped['action_history'][0]['views']);
        $this->assertSame(5, $mapped['action_history'][0]['clicks']);
        $this->assertSame('В процессе', $mapped['action_history'][0]['duration_label']);
        $this->assertFalse($mapped['action_history'][1]['in_progress']);
        $this->assertSame(1, $mapped['action_history'][1]['variant']);
        $this->assertSame(2, $mapped['total_rounds'] ?? null);
        $this->assertSame(2, $mapped['action_history_meta']['total_rounds'] ?? null);
    }

    public function test_map_experiment_photo_stats_ignore_cycles_eager_limit(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Limit Cycles');

        $product = AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 950101,
            'vendor_code' => 'LIM',
            'title' => 'Limit product',
        ]);

        $experiment = AbExperiment::query()->create([
            'ab_product_id' => $product->id,
            'cabinet_id' => $cabinet->id,
            'name' => 'Limit exp',
            'status' => WbAbTestStatus::Running,
            'progress' => 10,
            'wb_advert_id' => 840101,
            'started_at' => now()->subDays(2),
            'impressions_per_photo' => 100000,
            'impressions_per_round' => 100,
            'round_minutes' => 5,
            'cpm' => 350,
        ]);

        $photo1 = AbExperimentPhoto::query()->create([
            'ab_experiment_id' => $experiment->id,
            'cabinet_id' => $cabinet->id,
            'sort_order' => 0,
            'disk' => 'private',
            'path' => 'wb/ab-testing/lim1.jpg',
            'mime' => 'image/jpeg',
            'size' => 1,
        ]);
        $photo2 = AbExperimentPhoto::query()->create([
            'ab_experiment_id' => $experiment->id,
            'cabinet_id' => $cabinet->id,
            'sort_order' => 1,
            'disk' => 'private',
            'path' => 'wb/ab-testing/lim2.jpg',
            'mime' => 'image/jpeg',
            'size' => 1,
        ]);

        // 105 closed cycles: first 5 only on photo1 (would be dropped by limit 100 desc),
        // remaining 100 alternate — mapExperiment must still count early cycles.
        for ($i = 1; $i <= 105; $i++) {
            $photoId = $i <= 5 ? $photo1->id : (($i % 2 === 0) ? $photo1->id : $photo2->id);
            \App\Models\Subscribers\Wb\AbTesting\AbExperimentCycle::query()->create([
                'ab_experiment_id' => $experiment->id,
                'cabinet_id' => $cabinet->id,
                'ab_experiment_photo_id' => $photoId,
                'sequence' => $i,
                'started_at' => now()->subMinutes(105 - $i + 1),
                'ended_at' => now()->subMinutes(105 - $i),
                'end_reason' => 'time_limit',
                'views_start' => ($i - 1) * 10,
                'views_end' => $i * 10,
                'clicks_start' => 0,
                'clicks_end' => 0,
                'spend_start' => 0,
                'spend_end' => 0,
                'orders_start' => 0,
                'orders_end' => 0,
            ]);
        }

        $service = app(\App\Services\Subscriber\Wb\WbAbTestingService::class);

        // listPhotos path: no limited cycles relation
        $listPhotos = $service->listPhotos($experiment->fresh(['photos']));
        $listById = collect($listPhotos)->keyBy('id');

        // index path: cycles eager-loaded with limit(100) like experimentDetailRelations
        $experiment->load([
            'photos',
            // Intentionally wrong order without reorder would take oldest; detail relations use reorder.
            'cycles' => fn ($q) => $q->with('photo')->orderByDesc('sequence')->limit(100),
        ]);

        $mapped = $service->mapExperiment($experiment);
        $mapById = collect($mapped['photos'])->keyBy('id');

        $this->assertSame(
            $listById[$photo1->id]['stats']['views'],
            $mapById[$photo1->id]['stats']['views'],
            'Workspace stats must match listPhotos even when cycles are limited to 100',
        );
        $this->assertSame(
            $listById[$photo2->id]['stats']['views'],
            $mapById[$photo2->id]['stats']['views'],
        );
        // First 5 cycles (50 views) must be included for photo1, not dropped by limit.
        $this->assertGreaterThan(500, $mapById[$photo1->id]['stats']['views']);
        $this->assertSame(105, $mapped['total_rounds']);
        $this->assertSame(100, $mapped['action_history_meta']['shown']);
        $this->assertSame(105, $mapped['action_history_meta']['total_rounds']);

        // History must be the newest 100 sequences (6..105), not the oldest (1..100).
        $rounds = collect($mapped['action_history'])->pluck('round')->all();
        $this->assertSame(105, $rounds[0] ?? null);
        $this->assertSame(6, $rounds[array_key_last($rounds)] ?? null);
        $this->assertNotContains(1, $rounds);
        $this->assertNotContains(5, $rounds);
    }

    public function test_error_status_is_startable_and_editable(): void
    {
        $this->assertTrue(WbAbTestStatus::Error->isStartable());
        $this->assertTrue(WbAbTestStatus::Error->isEditable());
    }

    public function test_completed_result_delta_is_relative_to_max_ctr_winner(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Winner Delta');

        $product = AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 970001,
            'vendor_code' => 'WIN',
            'title' => 'Winner product',
        ]);

        $experiment = AbExperiment::query()->create([
            'ab_product_id' => $product->id,
            'cabinet_id' => $cabinet->id,
            'name' => 'Winner exp',
            'status' => WbAbTestStatus::Completed,
            'progress' => 100,
            'wb_advert_id' => 860001,
            'started_at' => now()->subHours(3),
            'finished_at' => now(),
            'impressions_per_photo' => 1000,
            'impressions_per_round' => 100,
            'round_minutes' => 60,
            'cpm' => 350,
        ]);

        $photo1 = AbExperimentPhoto::query()->create([
            'ab_experiment_id' => $experiment->id,
            'cabinet_id' => $cabinet->id,
            'sort_order' => 0,
            'disk' => 'private',
            'path' => 'wb/ab-testing/win1.jpg',
            'mime' => 'image/jpeg',
            'size' => 1,
        ]);
        $photo2 = AbExperimentPhoto::query()->create([
            'ab_experiment_id' => $experiment->id,
            'cabinet_id' => $cabinet->id,
            'sort_order' => 1,
            'disk' => 'private',
            'path' => 'wb/ab-testing/win2.jpg',
            'mime' => 'image/jpeg',
            'size' => 1,
        ]);
        $photo3 = AbExperimentPhoto::query()->create([
            'ab_experiment_id' => $experiment->id,
            'cabinet_id' => $cabinet->id,
            'sort_order' => 2,
            'disk' => 'private',
            'path' => 'wb/ab-testing/win3.jpg',
            'mime' => 'image/jpeg',
            'size' => 1,
        ]);

        // photo1 CTR = 1.5% (15/1000), photo2 = 2.2% (best), photo3 = 1.7% (17/1000)
        \App\Models\Subscribers\Wb\AbTesting\AbExperimentCycle::query()->create([
            'ab_experiment_id' => $experiment->id,
            'cabinet_id' => $cabinet->id,
            'ab_experiment_photo_id' => $photo1->id,
            'sequence' => 1,
            'started_at' => now()->subHours(3),
            'ended_at' => now()->subHours(2),
            'end_reason' => 'impressions_limit',
            'views_start' => 0,
            'views_end' => 1000,
            'clicks_start' => 0,
            'clicks_end' => 15,
            'spend_start' => 0,
            'spend_end' => 1,
            'orders_start' => 0,
            'orders_end' => 0,
        ]);
        \App\Models\Subscribers\Wb\AbTesting\AbExperimentCycle::query()->create([
            'ab_experiment_id' => $experiment->id,
            'cabinet_id' => $cabinet->id,
            'ab_experiment_photo_id' => $photo2->id,
            'sequence' => 2,
            'started_at' => now()->subHours(2),
            'ended_at' => now()->subHour(),
            'end_reason' => 'impressions_limit',
            'views_start' => 1000,
            'views_end' => 2000,
            'clicks_start' => 15,
            'clicks_end' => 37, // +22 clicks / 1000 views = 2.2%
            'spend_start' => 1,
            'spend_end' => 2,
            'orders_start' => 0,
            'orders_end' => 0,
        ]);
        \App\Models\Subscribers\Wb\AbTesting\AbExperimentCycle::query()->create([
            'ab_experiment_id' => $experiment->id,
            'cabinet_id' => $cabinet->id,
            'ab_experiment_photo_id' => $photo3->id,
            'sequence' => 3,
            'started_at' => now()->subHour(),
            'ended_at' => now(),
            'end_reason' => 'experiment_completed',
            'views_start' => 2000,
            'views_end' => 3000,
            'clicks_start' => 37,
            'clicks_end' => 54, // +17 / 1000 = 1.7%
            'spend_start' => 2,
            'spend_end' => 3,
            'orders_start' => 0,
            'orders_end' => 0,
        ]);

        $experiment->winner_photo_id = $photo2->id;
        $experiment->save();

        $experiment->load(['photos', 'cycles']);
        $service = app(\App\Services\Subscriber\Wb\WbAbTestingService::class);
        $mapped = $service->mapExperiment($experiment);
        $photosById = collect($mapped['photos'])->keyBy('id');

        $this->assertTrue($photosById[$photo2->id]['is_winner']);
        $this->assertFalse($photosById[$photo1->id]['is_winner']);
        $this->assertFalse($photosById[$photo3->id]['is_winner']);

        $this->assertSame(0.0, (float) $photosById[$photo2->id]['stats']['result_delta_pct']);
        // Losers are negative vs winner (max CTR), not vs first photo.
        $this->assertLessThan(0, (float) $photosById[$photo1->id]['stats']['result_delta_pct']);
        $this->assertLessThan(0, (float) $photosById[$photo3->id]['stats']['result_delta_pct']);
        // First photo is not the baseline: photo1 lag should be larger than photo3 (lower CTR).
        $this->assertLessThan(
            (float) $photosById[$photo3->id]['stats']['result_delta_pct'],
            (float) $photosById[$photo1->id]['stats']['result_delta_pct'],
        );
    }

    public function test_index_selected_experiment_includes_action_history(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Index History');

        $product = AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 960001,
            'vendor_code' => 'IDX',
            'title' => 'Index product',
        ]);

        $experiment = AbExperiment::query()->create([
            'ab_product_id' => $product->id,
            'cabinet_id' => $cabinet->id,
            'name' => 'Index exp',
            'status' => WbAbTestStatus::Running,
            'progress' => 5,
            'wb_advert_id' => 850001,
            'started_at' => now()->subHour(),
            'impressions_per_photo' => 1000,
            'impressions_per_round' => 100,
            'round_minutes' => 60,
            'cpm' => 350,
        ]);

        $photo = AbExperimentPhoto::query()->create([
            'ab_experiment_id' => $experiment->id,
            'cabinet_id' => $cabinet->id,
            'sort_order' => 0,
            'disk' => 'private',
            'path' => 'wb/ab-testing/idx1.jpg',
            'mime' => 'image/jpeg',
            'size' => 1,
        ]);

        \App\Models\Subscribers\Wb\AbTesting\AbExperimentCycle::query()->create([
            'ab_experiment_id' => $experiment->id,
            'cabinet_id' => $cabinet->id,
            'ab_experiment_photo_id' => $photo->id,
            'sequence' => 1,
            'started_at' => now()->subMinutes(5),
            'views_start' => 0,
            'views_end' => 10,
            'clicks_start' => 0,
            'clicks_end' => 1,
            'spend_start' => 0,
            'spend_end' => 0,
            'orders_start' => 0,
            'orders_end' => 0,
        ]);

        $response = $this->actingAs($user)->get(route('subscriber.wb.ab-testing.index', [
            'product_id' => $product->id,
            'experiment_id' => $experiment->id,
        ]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Subscriber/Wb/AbTesting/Index')
            ->has('selectedExperiment.action_history', 1)
            ->where('selectedExperiment.action_history.0.round', 1)
            ->where('selectedExperiment.action_history.0.in_progress', true)
            ->where('selectedExperiment.photos.0.stats.impressions', 10)
            ->where('selectedExperiment.photos.0.stats.clicks', 1)
        );
    }

    /**
     * @return array{0: AbProduct, 1: AbExperiment}
     */
    private function createDraftExperimentWithCampaign(
        WbCabinet $cabinet,
        string $paymentType = 'cpm',
    ): array {
        $product = AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 900200 + random_int(1, 9999),
            'vendor_code' => 'PHOTO-SKU',
            'title' => 'Photo product',
        ]);

        $advertId = 700001 + random_int(0, 99999);

        $experiment = AbExperiment::query()->create([
            'ab_product_id' => $product->id,
            'cabinet_id' => $cabinet->id,
            'name' => 'Photo draft',
            'status' => WbAbTestStatus::Draft,
            'progress' => 30,
            'wb_advert_id' => $advertId,
            'wb_advert_name' => 'Photo campaign',
            'campaign_bound_at' => now(),
        ]);

        AbCampaign::query()->create([
            'cabinet_id' => $cabinet->id,
            'wb_advert_id' => $advertId,
            'name' => 'Photo campaign',
            'bid_type' => $paymentType === 'cpc' ? 'manual' : 'unified',
            'payment_type' => $paymentType,
            'created_by_experiment_id' => $experiment->id,
        ]);

        return [$product, $experiment];
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
                $table->timestamp('rating_updated_at')->nullable();
                $table->timestamps();
                $table->unique(['cabinet_id', 'nm_id']);
            });
        } elseif (! Schema::hasColumn('wb_ab_products', 'rating_updated_at')) {
            Schema::table('wb_ab_products', function (Blueprint $table) {
                $table->timestamp('rating_updated_at')->nullable();
            });
        }

        if (! Schema::hasTable('wb_ab_experiments')) {
            Schema::create('wb_ab_experiments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('ab_product_id')->index();
                $table->unsignedBigInteger('cabinet_id')->index();
                $table->string('name');
                $table->string('status', 32)->default('draft');
                $table->unsignedTinyInteger('progress')->default(0);
                $table->unsignedBigInteger('wb_advert_id')->nullable();
                $table->string('wb_advert_name')->nullable();
                $table->timestamp('campaign_bound_at')->nullable();
                $table->unsignedInteger('impressions_per_photo')->nullable();
                $table->unsignedInteger('impressions_per_round')->nullable();
                $table->unsignedInteger('round_minutes')->nullable();
                $table->unsignedInteger('cpm')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->text('error_message')->nullable();
                $table->unsignedBigInteger('winner_photo_id')->nullable();
                $table->timestamp('last_processed_at')->nullable();
                $table->unsignedTinyInteger('consecutive_failures')->default(0);
                $table->timestamps();
            });
        } else {
            Schema::table('wb_ab_experiments', function (Blueprint $table) {
                $columns = [
                    'wb_advert_id' => fn (Blueprint $t) => $t->unsignedBigInteger('wb_advert_id')->nullable(),
                    'wb_advert_name' => fn (Blueprint $t) => $t->string('wb_advert_name')->nullable(),
                    'campaign_bound_at' => fn (Blueprint $t) => $t->timestamp('campaign_bound_at')->nullable(),
                    'impressions_per_photo' => fn (Blueprint $t) => $t->unsignedInteger('impressions_per_photo')->nullable(),
                    'impressions_per_round' => fn (Blueprint $t) => $t->unsignedInteger('impressions_per_round')->nullable(),
                    'round_minutes' => fn (Blueprint $t) => $t->unsignedInteger('round_minutes')->nullable(),
                    'cpm' => fn (Blueprint $t) => $t->unsignedInteger('cpm')->nullable(),
                    'started_at' => fn (Blueprint $t) => $t->timestamp('started_at')->nullable(),
                    'finished_at' => fn (Blueprint $t) => $t->timestamp('finished_at')->nullable(),
                    'error_message' => fn (Blueprint $t) => $t->text('error_message')->nullable(),
                    'winner_photo_id' => fn (Blueprint $t) => $t->unsignedBigInteger('winner_photo_id')->nullable(),
                    'last_processed_at' => fn (Blueprint $t) => $t->timestamp('last_processed_at')->nullable(),
                    'consecutive_failures' => fn (Blueprint $t) => $t->unsignedTinyInteger('consecutive_failures')->default(0),
                ];
                foreach ($columns as $name => $adder) {
                    if (! Schema::hasColumn('wb_ab_experiments', $name)) {
                        $adder($table);
                    }
                }
            });
        }

        if (! Schema::hasTable('wb_ab_campaigns')) {
            Schema::create('wb_ab_campaigns', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('cabinet_id')->index();
                $table->unsignedBigInteger('wb_advert_id');
                $table->string('name');
                $table->string('bid_type', 32)->nullable();
                $table->string('payment_type', 16)->nullable();
                $table->unsignedBigInteger('created_by_experiment_id')->nullable();
                $table->timestamps();
                $table->unique(['cabinet_id', 'wb_advert_id']);
            });
        }

        if (! Schema::hasTable('wb_ab_experiment_photos')) {
            Schema::create('wb_ab_experiment_photos', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('ab_experiment_id')->index();
                $table->unsignedBigInteger('cabinet_id')->index();
                $table->unsignedTinyInteger('sort_order')->default(0);
                $table->string('disk', 32)->default('private');
                $table->string('path');
                $table->string('original_name')->nullable();
                $table->string('mime', 64)->nullable();
                $table->unsignedInteger('size')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('wb_ab_experiment_cycles')) {
            Schema::create('wb_ab_experiment_cycles', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('ab_experiment_id')->index();
                $table->unsignedBigInteger('cabinet_id')->index();
                $table->unsignedBigInteger('ab_experiment_photo_id')->index();
                $table->unsignedInteger('sequence')->default(1);
                $table->timestamp('started_at');
                $table->timestamp('ended_at')->nullable();
                $table->string('end_reason', 32)->nullable();
                $table->unsignedBigInteger('views_start')->default(0);
                $table->unsignedBigInteger('views_end')->nullable();
                $table->unsignedBigInteger('clicks_start')->default(0);
                $table->unsignedBigInteger('clicks_end')->nullable();
                $table->decimal('spend_start', 14, 2)->default(0);
                $table->decimal('spend_end', 14, 2)->nullable();
                $table->unsignedInteger('orders_start')->default(0);
                $table->unsignedInteger('orders_end')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('wb_ab_experiment_events')) {
            Schema::create('wb_ab_experiment_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('ab_experiment_id')->index();
                $table->unsignedBigInteger('cabinet_id')->index();
                $table->string('type', 64);
                $table->string('message', 500);
                $table->json('meta')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }
    }
}
