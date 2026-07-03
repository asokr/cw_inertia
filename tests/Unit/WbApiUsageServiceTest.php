<?php

namespace Tests\Unit;

use App\Models\WbApiUsageStat;
use App\Services\Wb\WbApiUsageService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class WbApiUsageServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('wb_api_usage_stats');

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
    }

    public function tearDown(): void
    {
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
}
