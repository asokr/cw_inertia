<?php

namespace Tests\Unit;

use App\Models\WbApiRequestLog;
use App\Models\WbApiUsageStat;
use App\Services\Wb\WbApiUsageService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class WbApiUsageServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        Schema::dropIfExists('wb_api_usage_stats');
        Schema::dropIfExists('wb_api_request_logs');

        Schema::create('wb_api_usage_stats', function (Blueprint $table) {
            $table->id();
            $table->date('stat_date');
            $table->string('api_key_hash', 64);
            $table->text('api_key')->nullable();
            $table->unsignedBigInteger('requests_count')->default(0);
            $table->string('legal_entity')->nullable();
            $table->string('seller_id')->nullable();
            $table->timestamp('legal_entity_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['api_key_hash', 'stat_date']);
        });

        Schema::create('wb_api_request_logs', function (Blueprint $table) {
            $table->id();
            $table->string('seller_id')->nullable();
            $table->string('api_key_hash', 64);
            $table->text('api_key')->nullable();
            $table->string('method')->nullable();
            $table->string('endpoint')->nullable();
            $table->json('request_data')->nullable();
            $table->json('response_data')->nullable();
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function tearDown(): void
    {
        Schema::dropIfExists('wb_api_request_logs');
        Schema::dropIfExists('wb_api_usage_stats');

        Mockery::close();

        parent::tearDown();
    }

    public function test_it_records_request_and_syncs_legal_entity(): void
    {
        $service = Mockery::mock(WbApiUsageService::class)->makePartial();
        $service->shouldAllowMockingProtectedMethods();

        $service->shouldReceive('apiGetSellerInfo')
            ->once()
            ->andReturn(['data' => null, 'function' => 'apiGetSellerInfo']);

        $service->shouldReceive('parseApiResponse')
            ->once()
            ->andReturn([
                'success' => true,
                'data' => [
                    'name' => 'ООО «Ромашка»',
                    'sid' => '123456',
                ],
            ]);

        app()->instance(WbApiUsageService::class, $service);

        $service->recordRequest('test-api-key');
        $service->recordRequest('test-api-key');

        $stat = WbApiUsageStat::query()->first();

        $this->assertNotNull($stat);
        $this->assertSame(hash('sha256', 'test-api-key'), $stat->api_key_hash);
        $this->assertSame(2, $stat->requests_count);
        $this->assertSame('ООО «Ромашка»', $stat->legal_entity);
        $this->assertSame('123456', $stat->seller_id);
        $this->assertNotNull($stat->legal_entity_synced_at);

        $rawApiKey = DB::table('wb_api_usage_stats')->value('api_key');
        $this->assertNotSame('test-api-key', $rawApiKey);
    }

    public function test_failed_seller_info_sync_does_not_retry_until_ttl(): void
    {
        $service = Mockery::mock(WbApiUsageService::class)->makePartial();
        $service->shouldAllowMockingProtectedMethods();

        $service->shouldReceive('apiGetSellerInfo')
            ->once()
            ->andReturn(['data' => ['code' => 429, 'response' => ''], 'function' => 'apiGetSellerInfo']);

        $service->shouldReceive('parseApiResponse')
            ->once()
            ->andReturn([
                'success' => false,
                'code' => 429,
                'data' => 'Превышен лимит запросов. Функция: apiGetSellerInfo',
            ]);

        app()->instance(WbApiUsageService::class, $service);

        $service->recordRequest('failed-key');
        $service->recordRequest('failed-key');
        $service->recordRequest('failed-key');

        $stat = WbApiUsageStat::query()->first();

        $this->assertNotNull($stat);
        $this->assertNull($stat->legal_entity);
        $this->assertNotNull($stat->legal_entity_synced_at);
        $this->assertSame(3, $stat->requests_count);
    }

    public function test_seller_info_sync_runs_again_after_ttl_expires(): void
    {
        $service = Mockery::mock(WbApiUsageService::class)->makePartial();
        $service->shouldAllowMockingProtectedMethods();

        $service->shouldReceive('apiGetSellerInfo')
            ->twice()
            ->andReturn(['data' => null, 'function' => 'apiGetSellerInfo']);

        $service->shouldReceive('parseApiResponse')
            ->twice()
            ->andReturn([
                'success' => true,
                'data' => [
                    'name' => 'ООО «Ромашка»',
                    'sid' => '123456',
                ],
            ]);

        app()->instance(WbApiUsageService::class, $service);

        $service->recordRequest('ttl-key');

        $stat = WbApiUsageStat::query()->first();
        $this->assertNotNull($stat);

        // Expire TTL and free the 60s throttle slot.
        $stat->legal_entity_synced_at = now()->subHours(25);
        $stat->save();
        Cache::flush();

        $service->recordRequest('ttl-key');

        $stat->refresh();
        $this->assertSame('ООО «Ромашка»', $stat->legal_entity);
        $this->assertTrue($stat->legal_entity_synced_at->greaterThan(now()->subMinute()));
    }

    public function test_it_stores_normalized_response_body(): void
    {
        $service = Mockery::mock(WbApiUsageService::class)->makePartial();
        $service->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('apiGetSellerInfo')->andReturn(['data' => null, 'function' => 'apiGetSellerInfo']);
        $service->shouldReceive('parseApiResponse')->andReturn([
            'success' => true,
            'data' => ['name' => 'Test', 'sid' => '1'],
        ]);
        app()->instance(WbApiUsageService::class, $service);

        $service->recordRequest(
            'response-key',
            'POST',
            'https://suppliers-api.wildberries.ru/content/v2/get/cards/list',
            ['take' => 10],
            200,
            '{"result":{"cards":[{"nmID":1}]},"error":false}',
        );

        $log = WbApiRequestLog::query()->first();

        $this->assertNotNull($log);
        $this->assertSame(['take' => 10], $log->request_data);
        $this->assertSame(200, $log->response_code);
        $this->assertEquals([
            'result' => ['cards' => [['nmID' => 1]]],
            'error' => false,
        ], $log->response_data);
    }

    public function test_it_truncates_large_response_body(): void
    {
        $service = Mockery::mock(WbApiUsageService::class)->makePartial();
        $service->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('apiGetSellerInfo')->andReturn(['data' => null, 'function' => 'apiGetSellerInfo']);
        $service->shouldReceive('parseApiResponse')->andReturn([
            'success' => true,
            'data' => ['name' => 'Test', 'sid' => '1'],
        ]);
        app()->instance(WbApiUsageService::class, $service);

        $large = str_repeat('a', 70000);

        $service->recordRequest(
            'truncate-key',
            'GET',
            'https://example.com/api',
            null,
            200,
            $large,
        );

        $log = WbApiRequestLog::query()->first();

        $this->assertNotNull($log);
        $this->assertIsArray($log->response_data);
        $this->assertTrue($log->response_data['truncated'] ?? false);
        $this->assertSame(70000, $log->response_data['original_bytes'] ?? null);
        $this->assertLessThanOrEqual(65536, strlen($log->response_data['raw'] ?? ''));
    }
}
