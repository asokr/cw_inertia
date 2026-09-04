<?php

namespace Tests\Feature\Web\Subscriber\Oz;

use App\Enums\OzAbTestStatus;
use App\Jobs\Oz\AbTesting\ProcessOzAbCabinetTickJob;
use App\Models\Subscribers\Oz\AbTesting\AbCampaign;
use App\Models\Subscribers\Oz\AbTesting\AbExperiment;
use App\Models\Subscribers\Oz\AbTesting\AbExperimentCycle;
use App\Models\Subscribers\Oz\AbTesting\AbExperimentPhoto;
use App\Models\Subscribers\Oz\AbTesting\AbProduct;
use App\Models\Subscribers\Oz\OzCabinet;
use App\Models\Subscribers\Subscribers;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\User;
use App\Services\Ozon\OzonApiService;
use App\Services\Ozon\OzonPerformanceApiService;
use App\Services\Subscriber\Oz\AbTesting\OzAbExperimentEngine;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Web\Auth\WebAuthTestCase;

class OzAbTestingTest extends WebAuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setupOzAbTestingSchema();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::firstOrCreate([
            'name' => 'subscriber oz ab testing',
            'guard_name' => 'web',
        ]);
    }

    public function test_guest_cannot_access_index(): void
    {
        $this->get('/panel/oz/ab-testing')->assertRedirect('/login');
    }

    public function test_user_without_permission_cannot_access_index(): void
    {
        $user = $this->createSubscriberUser();

        $this->actingAs($user)
            ->get('/panel/oz/ab-testing')
            ->assertForbidden();
    }

    public function test_subscriber_with_permission_sees_no_cabinet_without_unified_cabinet(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);

        $this->actingAs($user)
            ->get('/panel/oz/ab-testing')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Oz/Shared/NoCabinet')
                ->where('toolName', 'A/B-тестирование'));
    }

    public function test_index_renders_workspace_for_selected_cabinet(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Test Ozon Cabinet');

        $this->actingAs($user)
            ->get('/panel/oz/ab-testing')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Oz/AbTesting/Index')
                ->where('cabinet.id', $cabinet->id)
                ->where('cabinet.name', 'Test Ozon Cabinet')
                ->has('products')
                ->has('productsMeta'));
    }

    public function test_search_filters_products_by_sku_and_offer_id(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Search Cabinet');

        AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'oz_product_id' => 11,
            'offer_id' => 'SKU-AAA',
            'sku' => 111111,
            'title' => 'Product A',
        ]);
        AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'oz_product_id' => 22,
            'offer_id' => 'SKU-BBB',
            'sku' => 222222,
            'title' => 'Product B',
        ]);

        $this->actingAs($user)
            ->get('/panel/oz/ab-testing?search=111111')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Oz/AbTesting/Index')
                ->has('products', 1)
                ->where('products.0.sku_count', 1)
                ->where('products.0.skus.0.sku', 111111)
                ->where('products.0.skus.0.test_status', 'not_created'));

        $this->actingAs($user)
            ->get('/panel/oz/ab-testing?search=SKU-BBB')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('products', 1)
                ->where('products.0.skus.0.offer_id', 'SKU-BBB'));

        $this->actingAs($user)
            ->get('/panel/oz/ab-testing?search=Product%20A')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('products', 1)
                ->where('products.0.title', 'Product A'));
    }

    public function test_products_with_same_model_or_title_share_group_key(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Group Cabinet');

        AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'oz_product_id' => 11,
            'offer_id' => 'TEE-S',
            'sku' => 101,
            'model_id' => 900,
            'title' => 'Футболка',
        ]);
        AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'oz_product_id' => 12,
            'offer_id' => 'TEE-M',
            'sku' => 102,
            'model_id' => 900,
            'title' => 'Футболка',
        ]);
        AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'oz_product_id' => 21,
            'offer_id' => 'HAT-1',
            'sku' => 201,
            'title' => 'Кепка',
        ]);

        $this->actingAs($user)
            ->get('/panel/oz/ab-testing')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Oz/AbTesting/Index')
                ->has('products', 2)
                ->where('products.0.group_key', 't:кепка')
                ->where('products.0.sku_count', 1)
                ->where('products.0.skus.0.offer_id', 'HAT-1')
                ->where('products.1.group_key', 'm:900')
                ->where('products.1.sku_count', 2)
                ->where('products.1.skus.0.sku', 101)
                ->where('products.1.skus.1.sku', 102)
                ->where('productsMeta.total', 2)
                ->missing('products.0.brand')
                ->missing('products.0.price')
                ->missing('products.0.sku'));

        $this->actingAs($user)
            ->get('/panel/oz/ab-testing?per_page=1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('products', 1)
                ->where('productsMeta.total', 2)
                ->where('productsMeta.last_page', 2)
                ->where('products.0.group_key', 't:кепка'));

        $this->actingAs($user)
            ->get('/panel/oz/ab-testing?search=TEE-S')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('products', 1)
                ->where('products.0.group_key', 'm:900')
                ->where('products.0.sku_count', 2));
    }

    public function test_sync_products_accepts_primary_image_as_array(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Sync Cabinet');

        $api = Mockery::mock(OzonApiService::class);
        $api->shouldReceive('getProductsList')
            ->once()
            ->andReturn([
                'success' => true,
                'status' => 200,
                'data' => [
                    'result' => [
                        'items' => [
                            ['product_id' => 101, 'offer_id' => 'OFF-1'],
                        ],
                        'last_id' => '',
                    ],
                ],
            ]);
        $api->shouldReceive('getProductsInfo')
            ->once()
            ->andReturn([
                'success' => true,
                'status' => 200,
                'data' => [
                    'items' => [
                        [
                            'id' => 101,
                            'offer_id' => 'OFF-1',
                            'sku' => 0,
                            'name' => 'Товар',
                            'primary_image' => ['https://cdn.example/main.jpg'],
                            'images' => ['https://cdn.example/a.jpg'],
                            'sources' => [['sku' => 777]],
                            'model_info' => ['model_id' => 55, 'count' => 3],
                        ],
                    ],
                ],
            ]);
        $this->app->instance(OzonApiService::class, $api);

        $this->actingAs($user)
            ->post('/panel/oz/ab-testing/sync')
            ->assertRedirect();

        $this->assertDatabaseHas('oz_ab_products', [
            'cabinet_id' => $cabinet->id,
            'oz_product_id' => 101,
            'offer_id' => 'OFF-1',
            'sku' => 777,
            'model_id' => 55,
            'title' => 'Товар',
            'photo_url' => 'https://cdn.example/main.jpg',
        ]);
    }

    public function test_store_experiment_creates_draft(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Draft Cabinet');
        $product = AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'oz_product_id' => 55,
            'offer_id' => 'OFF-1',
            'sku' => 555,
            'title' => 'Draft product',
        ]);

        $this->actingAs($user)
            ->post('/panel/oz/ab-testing/experiments', [
                'product_id' => $product->id,
                'name' => 'Мой тест',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('oz_ab_experiments', [
            'ab_product_id' => $product->id,
            'cabinet_id' => $cabinet->id,
            'name' => 'Мой тест',
            'status' => 'draft',
            'sku' => 555,
        ]);
    }

    public function test_experiment_workspace_exposes_start_checks_as_list(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Checks Cabinet');
        [$product, $experiment] = $this->createDraft($cabinet);

        $this->actingAs($user)
            ->get('/panel/oz/ab-testing?product_id='.$product->id.'&experiment_id='.$experiment->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Oz/AbTesting/Index')
                ->has('selectedExperiment.start_checks', 4)
                ->where('selectedExperiment.start_checks.0.key', 'status')
                ->where('selectedExperiment.start_checks.0.ok', true)
                ->where('selectedExperiment.start_checks.1.key', 'settings')
                ->where('selectedExperiment.start_checks.2.key', 'photos')
                ->where('selectedExperiment.start_checks.3.key', 'campaign')
                ->where('selectedExperiment.start_checks.3.ok', false));
    }

    public function test_round_minutes_cannot_be_shorter_than_30(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Settings Cabinet');
        [$product, $experiment] = $this->createDraft($cabinet);

        $this->actingAs($user)
            ->patchJson('/panel/oz/ab-testing/experiments/'.$experiment->id.'/settings', [
                'impressions_per_photo' => 1000,
                'impressions_per_round' => 100,
                'round_minutes' => 5,
                'cpm' => 15,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['round_minutes']);

        $this->actingAs($user)
            ->patchJson('/panel/oz/ab-testing/experiments/'.$experiment->id.'/settings', [
                'impressions_per_photo' => 1000,
                'impressions_per_round' => 100,
                'round_minutes' => 30,
                'cpm' => 15,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('oz_ab_experiments', [
            'id' => $experiment->id,
            'round_minutes' => 30,
        ]);
    }

    public function test_list_campaigns_returns_usable_sku_campaigns(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Ads Cabinet', withPerformance: true);
        [$product, $experiment] = $this->createDraft($cabinet);

        $this->mockPerformance([
            'token' => true,
            'list' => [
                ['id' => 111, 'title' => 'Ours', 'state' => 'CAMPAIGN_STATE_INACTIVE', 'advObjectType' => 'SKU', 'paymentType' => 'CPC'],
                ['id' => 222, 'title' => 'Seller', 'state' => 'CAMPAIGN_STATE_INACTIVE', 'advObjectType' => 'SKU', 'paymentType' => 'CPC'],
                ['id' => 333, 'title' => 'Banner', 'state' => 'CAMPAIGN_STATE_RUNNING', 'advObjectType' => 'BANNER'],
                ['id' => 444, 'title' => 'Archived', 'state' => 'CAMPAIGN_STATE_ARCHIVED', 'advObjectType' => 'SKU'],
            ],
            'objects' => [
                111 => [111111],
                222 => [999],
            ],
        ]);

        AbCampaign::query()->create([
            'cabinet_id' => $cabinet->id,
            'oz_campaign_id' => 111,
            'name' => 'Ours',
            'created_by_experiment_id' => $experiment->id,
        ]);

        $this->actingAs($user)
            ->getJson('/panel/oz/ab-testing/campaigns?experiment_id='.$experiment->id)
            ->assertOk()
            ->assertJsonPath('success', true);

        $ids = collect($this->actingAs($user)
            ->getJson('/panel/oz/ab-testing/campaigns?experiment_id='.$experiment->id)
            ->json('campaigns'))->pluck('id')->all();

        $this->assertContains(111, $ids);
        $this->assertContains(222, $ids);
        $this->assertNotContains(333, $ids);
        $this->assertNotContains(444, $ids);
    }

    public function test_list_campaigns_without_performance_keys_asks_to_fill_them(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'No Perf');
        [, $experiment] = $this->createDraft($cabinet);

        $this->actingAs($user)
            ->getJson('/panel/oz/ab-testing/campaigns?experiment_id='.$experiment->id)
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('messages.0', 'Укажите ключи рекламы Performance API в кабинете Ozon.');
    }

    public function test_list_campaigns_with_invalid_performance_keys_returns_connection_error(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Bad Perf', withPerformance: true);
        [, $experiment] = $this->createDraft($cabinet);

        $mock = Mockery::mock(OzonPerformanceApiService::class);
        $mock->shouldReceive('getAccessToken')
            ->once()
            ->andReturn([
                'success' => false,
                'status' => 401,
                'data' => [
                    'error' => 'invalid_client',
                    'error_description' => 'Client authentication failed',
                ],
            ]);
        $this->app->instance(OzonPerformanceApiService::class, $mock);

        $this->actingAs($user)
            ->getJson('/panel/oz/ab-testing/campaigns?experiment_id='.$experiment->id)
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('messages.0', 'Неверные данные для подключения');
    }

    public function test_list_campaigns_rate_limit_returns_friendly_message(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Rate Limit', withPerformance: true);
        [, $experiment] = $this->createDraft($cabinet);

        $mock = Mockery::mock(OzonPerformanceApiService::class);
        $mock->shouldReceive('getAccessToken')
            ->once()
            ->andReturn(['success' => true, 'status' => 200, 'data' => ['access_token' => 'tok']]);
        $mock->shouldReceive('listCampaigns')
            ->once()
            ->andReturn([
                'success' => false,
                'status' => 429,
                'data' => ['error' => 'Превышен лимит активных запросов (максимум 1)'],
            ]);
        $this->app->instance(OzonPerformanceApiService::class, $mock);

        $this->actingAs($user)
            ->getJson('/panel/oz/ab-testing/campaigns?experiment_id='.$experiment->id)
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('messages.0', 'Ozon сейчас не принимает запросы. Подождите несколько секунд и обновите список.');
    }

    public function test_create_campaign_sends_placement_and_target_bids_strategy(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Create Cabinet', withPerformance: true);
        [$product, $experiment] = $this->createDraft($cabinet);

        $perf = $this->mockPerformance(['token' => true]);
        $perf->shouldReceive('createCpcProductCampaign')
            ->once()
            ->withArgs(function (string $token, array $payload) use ($product) {
                return $token === 'tok'
                    && ($payload['placement'] ?? null) === 'PLACEMENT_SEARCH_AND_CATEGORY'
                    && ($payload['productAutopilotStrategy'] ?? null) === 'TARGET_BIDS'
                    && ! array_key_exists('productCampaignMode', $payload)
                    && ($payload['products'][0]['sku'] ?? null) === (string) $product->sku;
            })
            ->andReturn(['success' => true, 'status' => 200, 'data' => ['campaignId' => 555]]);

        $this->actingAs($user)
            ->postJson('/panel/oz/ab-testing/campaigns', [
                'experiment_id' => $experiment->id,
                'name' => 'A/B тест',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('campaign.id', 555);

        $this->assertDatabaseHas('oz_ab_experiments', [
            'id' => $experiment->id,
            'oz_campaign_id' => 555,
            'sku' => 111111,
        ]);
    }

    public function test_prepare_adds_sku_when_missing(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Prepare Cabinet', withPerformance: true);
        [$product, $experiment] = $this->createDraft($cabinet);

        $perf = $this->mockPerformance([
            'token' => true,
            'find' => [
                'id' => 777,
                'title' => 'Existing',
                'state' => 'CAMPAIGN_STATE_INACTIVE',
                'advObjectType' => 'SKU',
            ],
            'objects' => [777 => [1]],
        ]);
        $perf->shouldReceive('addCampaignProducts')
            ->once()
            ->andReturn(['success' => true, 'status' => 200, 'data' => []]);

        $this->actingAs($user)
            ->postJson('/panel/oz/ab-testing/campaigns/777/prepare', [
                'experiment_id' => $experiment->id,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('experiment.wb_advert_id', 777);

        $this->assertDatabaseHas('oz_ab_experiments', [
            'id' => $experiment->id,
            'oz_campaign_id' => 777,
            'sku' => 111111,
        ]);
    }

    public function test_cannot_delete_campaign_not_created_by_tool(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Delete Cabinet', withPerformance: true);
        $this->createDraft($cabinet);

        AbCampaign::query()->create([
            'cabinet_id' => $cabinet->id,
            'oz_campaign_id' => 12,
            'name' => 'Seller campaign',
            'created_by_experiment_id' => null,
        ]);

        $this->actingAs($user)
            ->deleteJson('/panel/oz/ab-testing/campaigns/12')
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_cannot_start_second_running_experiment_on_same_campaign(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Lock Cabinet', withPerformance: true);

        $productA = AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'oz_product_id' => 1,
            'offer_id' => 'A',
            'sku' => 10,
            'title' => 'A',
        ]);
        $productB = AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'oz_product_id' => 2,
            'offer_id' => 'B',
            'sku' => 20,
            'title' => 'B',
        ]);

        AbExperiment::query()->create([
            'ab_product_id' => $productA->id,
            'cabinet_id' => $cabinet->id,
            'name' => 'Running',
            'status' => OzAbTestStatus::Running,
            'oz_campaign_id' => 50,
            'sku' => 10,
            'impressions_per_photo' => 1000,
            'impressions_per_round' => 100,
            'round_minutes' => 30,
            'cpm' => 15,
        ]);

        $draft = AbExperiment::query()->create([
            'ab_product_id' => $productB->id,
            'cabinet_id' => $cabinet->id,
            'name' => 'Draft',
            'status' => OzAbTestStatus::Draft,
            'oz_campaign_id' => 50,
            'sku' => 20,
            'impressions_per_photo' => 1000,
            'impressions_per_round' => 100,
            'round_minutes' => 30,
            'cpm' => 15,
        ]);

        Storage::fake('public');
        foreach ([1, 2] as $i) {
            $path = "oz/ab-testing/{$cabinet->id}/{$draft->id}/{$i}.jpg";
            Storage::disk('public')->put($path, 'img');
            AbExperimentPhoto::query()->create([
                'ab_experiment_id' => $draft->id,
                'cabinet_id' => $cabinet->id,
                'sort_order' => $i - 1,
                'disk' => 'public',
                'path' => $path,
                'mime' => 'image/jpeg',
                'size' => 3,
            ]);
        }

        $this->actingAs($user)
            ->postJson('/panel/oz/ab-testing/experiments/'.$draft->id.'/start')
            ->assertStatus(422)
            ->assertJsonFragment(['Эта кампания уже используется в запущенном эксперименте «Running». Дождитесь завершения или остановите его.']);
    }

    public function test_photo_aggregates_subtract_baseline(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Stats Cabinet');
        [$product, $experiment] = $this->createDraft($cabinet);

        $photo = AbExperimentPhoto::query()->create([
            'ab_experiment_id' => $experiment->id,
            'cabinet_id' => $cabinet->id,
            'sort_order' => 0,
            'disk' => 'public',
            'path' => 'oz/ab-testing/x.jpg',
        ]);

        AbExperimentCycle::query()->create([
            'ab_experiment_id' => $experiment->id,
            'cabinet_id' => $cabinet->id,
            'ab_experiment_photo_id' => $photo->id,
            'sequence' => 1,
            'started_at' => now()->subHour(),
            'ended_at' => now(),
            'end_reason' => AbExperimentCycle::END_TIME,
            'views_start' => 500000,
            'views_end' => 505000,
            'clicks_start' => 12000,
            'clicks_end' => 12150,
            'spend_start' => 10,
            'spend_end' => 12,
        ]);

        $agg = app(OzAbExperimentEngine::class)->photoAggregates($experiment->fresh());
        $this->assertSame(5000, $agg[$photo->id]['views']);
        $this->assertSame(150, $agg[$photo->id]['clicks']);
        $this->assertEqualsWithDelta(3.0, $agg[$photo->id]['ctr'], 0.01);
    }

    public function test_extract_sku_stats_uses_sku_rows_not_other_products(): void
    {
        $engine = app(OzAbExperimentEngine::class);
        $stats = $engine->extractSkuStats([
            'rows' => [
                ['sku' => '111111', 'campaignId' => '9', 'views' => '100', 'clicks' => '4', 'expense' => '10'],
                ['sku' => '222222', 'campaignId' => '9', 'views' => '900', 'clicks' => '40', 'expense' => '90'],
            ],
        ], 9, 111111);

        $this->assertSame(['views' => 100, 'clicks' => 4, 'spend' => 10.0, 'orders' => 0], $stats);
    }

    public function test_cabinet_tick_loads_all_running_campaigns_in_one_stats_request(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Tick Cabinet', withPerformance: true);

        $first = $this->createRunningExperiment($cabinet, 10, 101);
        $second = $this->createRunningExperiment($cabinet, 20, 202);

        $perf = Mockery::mock(OzonPerformanceApiService::class);
        $perf->shouldReceive('getAccessToken')
            ->once()
            ->andReturn(['success' => true, 'status' => 200, 'data' => ['access_token' => 'tok']]);
        $perf->shouldReceive('getProductSkuStatistics')
            ->once()
            ->withArgs(function ($token, array $payload) {
                $ids = array_map('strval', $payload['campaignIds'] ?? []);
                sort($ids);

                return $token === 'tok' && $ids === ['10', '20'];
            })
            ->andReturn([
                'success' => true,
                'status' => 200,
                'data' => [
                    'rows' => [
                        ['sku' => 101, 'campaignId' => 10, 'views' => 15, 'clicks' => 2, 'expense' => 3],
                        ['sku' => 202, 'campaignId' => 20, 'views' => 40, 'clicks' => 5, 'expense' => 8],
                    ],
                ],
            ]);
        $this->app->instance(OzonPerformanceApiService::class, $perf);

        $result = app(OzAbExperimentEngine::class)->processCabinet((int) $cabinet->id);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['reschedule']);
        $this->assertSame(2, $result['processed']);
        $this->assertSame(15, (int) $first['cycle']->fresh()->views_end);
        $this->assertSame(40, (int) $second['cycle']->fresh()->views_end);
    }

    public function test_fallback_tick_dispatches_one_job_per_cabinet(): void
    {
        Queue::fake();

        $user = $this->createSubscriberUser(withPermission: true);
        $cabinetA = $this->createUnifiedCabinet($user, 'Cab A', withPerformance: true);
        $cabinetB = $this->createUnifiedCabinet($user, 'Cab B', withPerformance: true);

        $this->createRunningExperiment($cabinetA, 11, 111);
        $this->createRunningExperiment($cabinetA, 12, 112);
        $this->createRunningExperiment($cabinetB, 21, 211);

        Artisan::call('subscriber:oz-ab-testing-tick');

        Queue::assertPushed(ProcessOzAbCabinetTickJob::class, 2);
        Queue::assertPushed(ProcessOzAbCabinetTickJob::class, fn (ProcessOzAbCabinetTickJob $job) => $job->cabinetId === (int) $cabinetA->id);
        Queue::assertPushed(ProcessOzAbCabinetTickJob::class, fn (ProcessOzAbCabinetTickJob $job) => $job->cabinetId === (int) $cabinetB->id);
    }

    public function test_start_without_performance_keys_fails(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'No Perf');
        [$product, $experiment] = $this->createDraft($cabinet);
        $experiment->update([
            'oz_campaign_id' => 1,
            'impressions_per_photo' => 1000,
            'impressions_per_round' => 100,
            'round_minutes' => 30,
            'cpm' => 15,
        ]);

        $this->actingAs($user)
            ->postJson('/panel/oz/ab-testing/experiments/'.$experiment->id.'/start')
            ->assertStatus(422);
    }

    /**
     * @return array{0: AbProduct, 1: AbExperiment}
     */
    private function createDraft(OzCabinet $cabinet): array
    {
        $product = AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'oz_product_id' => 101,
            'offer_id' => 'OFF-AB',
            'sku' => 111111,
            'title' => 'AB product',
        ]);
        $experiment = AbExperiment::query()->create([
            'ab_product_id' => $product->id,
            'cabinet_id' => $cabinet->id,
            'name' => 'Draft',
            'status' => OzAbTestStatus::Draft,
            'sku' => 111111,
        ]);

        return [$product, $experiment];
    }

    /**
     * @return array{product: AbProduct, experiment: AbExperiment, cycle: AbExperimentCycle}
     */
    private function createRunningExperiment(OzCabinet $cabinet, int $campaignId, int $sku): array
    {
        $product = AbProduct::query()->create([
            'cabinet_id' => $cabinet->id,
            'oz_product_id' => $sku,
            'offer_id' => 'OFF-'.$sku,
            'sku' => $sku,
            'title' => 'Product '.$sku,
        ]);
        $experiment = AbExperiment::query()->create([
            'ab_product_id' => $product->id,
            'cabinet_id' => $cabinet->id,
            'name' => 'Running '.$sku,
            'status' => OzAbTestStatus::Running,
            'oz_campaign_id' => $campaignId,
            'sku' => $sku,
            'impressions_per_photo' => 100000,
            'impressions_per_round' => 10000,
            'round_minutes' => 30,
            'cpm' => 15,
            'started_at' => now()->subMinutes(5),
        ]);
        $photo = AbExperimentPhoto::query()->create([
            'ab_experiment_id' => $experiment->id,
            'cabinet_id' => $cabinet->id,
            'sort_order' => 0,
            'disk' => 'public',
            'path' => 'oz/ab-testing/'.$cabinet->id.'/'.$experiment->id.'/a.jpg',
        ]);
        $cycle = AbExperimentCycle::query()->create([
            'ab_experiment_id' => $experiment->id,
            'cabinet_id' => $cabinet->id,
            'ab_experiment_photo_id' => $photo->id,
            'sequence' => 1,
            'started_at' => now()->subMinutes(5),
            'views_start' => 0,
            'clicks_start' => 0,
            'spend_start' => 0,
            'orders_start' => 0,
        ]);

        return ['product' => $product, 'experiment' => $experiment, 'cycle' => $cycle];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function mockPerformance(array $config): Mockery\MockInterface
    {
        $mock = Mockery::mock(OzonPerformanceApiService::class);
        if ($config['token'] ?? false) {
            $mock->shouldReceive('getAccessToken')
                ->andReturn(['success' => true, 'status' => 200, 'data' => ['access_token' => 'tok']]);
        }
        if (isset($config['list'])) {
            $mock->shouldReceive('listCampaigns')
                ->andReturn(['success' => true, 'status' => 200, 'data' => ['list' => $config['list']]]);
        }
        if (isset($config['find'])) {
            $mock->shouldReceive('listCampaigns')
                ->andReturn(['success' => true, 'status' => 200, 'data' => ['list' => [$config['find']]]]);
        }
        $objects = $config['objects'] ?? [];
        $mock->shouldReceive('getCampaignObjects')
            ->andReturnUsing(function ($token, $id) use ($objects) {
                $skus = $objects[(int) $id] ?? [];

                return [
                    'success' => true,
                    'status' => 200,
                    'data' => ['list' => array_map(fn ($sku) => ['sku' => $sku], $skus)],
                ];
            });

        $this->app->instance(OzonPerformanceApiService::class, $mock);

        return $mock;
    }

    private function createSubscriberUser(bool $withPermission = false): User
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
        ]);
        $user->assignRole('Подписчик');
        if ($withPermission) {
            $user->givePermissionTo('subscriber oz ab testing');
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

    private function createUnifiedCabinet(User $user, string $name, bool $withPerformance = false): OzCabinet
    {
        $cabinet = OzCabinet::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'client_id' => 'client-'.uniqid(),
            'apikey' => 'test-api-key',
            'performance_client_id' => $withPerformance ? 'perf-id' : null,
            'performance_client_secret' => $withPerformance ? 'perf-secret' : null,
        ]);
        $user->forceFill(['selected_oz_cabinet_id' => $cabinet->id])->save();

        return $cabinet;
    }

    private function setupOzAbTestingSchema(): void
    {
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'selected_oz_cabinet_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedBigInteger('selected_oz_cabinet_id')->nullable();
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

        if (! Schema::hasTable('oz_ab_products')) {
            Schema::create('oz_ab_products', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('cabinet_id')->index();
                $table->unsignedBigInteger('oz_product_id');
                $table->string('offer_id');
                $table->unsignedBigInteger('sku')->nullable();
                $table->unsignedBigInteger('model_id')->nullable();
                $table->string('title')->nullable();
                $table->string('brand')->nullable();
                $table->string('photo_url', 1024)->nullable();
                $table->decimal('price', 12, 2)->nullable();
                $table->timestamps();
            });
        } elseif (! Schema::hasColumn('oz_ab_products', 'model_id')) {
            Schema::table('oz_ab_products', function (Blueprint $table) {
                $table->unsignedBigInteger('model_id')->nullable();
            });
        }
        if (! Schema::hasTable('oz_ab_experiments')) {
            Schema::create('oz_ab_experiments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('ab_product_id')->index();
                $table->unsignedBigInteger('cabinet_id')->index();
                $table->string('name');
                $table->string('status', 32)->default('draft');
                $table->unsignedTinyInteger('progress')->default(0);
                $table->unsignedBigInteger('oz_campaign_id')->nullable();
                $table->string('oz_campaign_name')->nullable();
                $table->timestamp('campaign_bound_at')->nullable();
                $table->unsignedBigInteger('sku')->nullable();
                $table->json('gallery_snapshot')->nullable();
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
        }
        if (! Schema::hasTable('oz_ab_campaigns')) {
            Schema::create('oz_ab_campaigns', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('cabinet_id')->index();
                $table->unsignedBigInteger('oz_campaign_id');
                $table->string('name');
                $table->string('state', 64)->nullable();
                $table->string('payment_type', 16)->nullable();
                $table->unsignedBigInteger('created_by_experiment_id')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('oz_ab_experiment_photos')) {
            Schema::create('oz_ab_experiment_photos', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('ab_experiment_id')->index();
                $table->unsignedBigInteger('cabinet_id')->index();
                $table->unsignedTinyInteger('sort_order')->default(0);
                $table->string('disk', 32)->default('public');
                $table->string('path');
                $table->string('original_name')->nullable();
                $table->string('mime', 64)->nullable();
                $table->unsignedInteger('size')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('oz_ab_experiment_cycles')) {
            Schema::create('oz_ab_experiment_cycles', function (Blueprint $table) {
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
        if (! Schema::hasTable('oz_ab_experiment_events')) {
            Schema::create('oz_ab_experiment_events', function (Blueprint $table) {
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
