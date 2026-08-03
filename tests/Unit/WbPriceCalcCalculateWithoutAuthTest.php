<?php

namespace Tests\Unit;

use App\Models\Subscribers\Wb\PriceCalculation\PriceCalculationV2Settings;
use App\Models\Subscribers\Wb\PriceCalculation\PriceCalculationV3Data;
use App\Models\Subscribers\Wb\WbCabinet;
use App\Models\User;
use App\Services\Subscriber\Wb\WbPriceCalculationV3Service;
use App\Services\Wb\WbPriceCalculationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

/**
 * Queue jobs have no session: calculate must work without Auth::id().
 */
class WbPriceCalcCalculateWithoutAuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupSchema();
        Auth::logout();
    }

    public function test_run_calculate_for_cabinet_works_without_authenticated_user(): void
    {
        $user = User::factory()->create();
        $cabinet = WbCabinet::query()->create([
            'user_id' => $user->id,
            'name' => 'Job Cabinet',
            'apikey' => 'test-key',
            'api_key_hash' => hash('sha256', 'test-key'),
        ]);

        PriceCalculationV2Settings::query()->create([
            'cabinet_id' => $cabinet->id,
            'hide_sizes' => true,
            'commission_source' => 'manual',
            'acquiring_source' => 'manual',
        ]);

        PriceCalculationV3Data::query()->create([
            'cabinet_id' => $cabinet->id,
            'nm_id' => 111,
            'barcode' => 'bc1',
            'cost_price' => 100,
            'margin_percent' => 20,
            'volume_liters' => 1,
            'extra_liters' => 0,
        ]);

        $this->assertNull(Auth::id());

        $wb = Mockery::mock(WbPriceCalculationService::class);
        $wb->shouldReceive('getSales')->andReturn(['code' => 200, 'response' => '[]']);
        $wb->shouldReceive('getWhTariffs')->andReturn(['code' => 200, 'response' => '{}']);
        $wb->shouldReceive('getSalesFunnelProducts')->andReturn(['code' => 200, 'response' => '{}']);
        $wb->shouldReceive('parseApiResponse')->andReturnUsing(function ($resp, $fn = '') {
            if (($resp['code'] ?? null) !== 200) {
                return ['success' => false, 'code' => 503, 'data' => 'err'];
            }

            if ($fn === 'getWhTariffs') {
                return [
                    'success' => true,
                    'code' => 200,
                    'data' => [
                        'response' => [
                            'data' => [
                                'warehouseList' => [
                                    ['warehouseName' => 'Коледино', 'boxDeliveryBase' => 50, 'boxDeliveryLiter' => 10],
                                ],
                            ],
                        ],
                    ],
                ];
            }

            if ($fn === 'getSalesFunnelProducts') {
                return [
                    'success' => true,
                    'code' => 200,
                    'data' => ['data' => ['products' => []]],
                ];
            }

            // getSales — empty list is ok
            return ['success' => true, 'code' => 200, 'data' => []];
        });
        $this->app->instance(WbPriceCalculationService::class, $wb);

        $service = app(WbPriceCalculationV3Service::class);
        $result = $service->runCalculateForCabinet($cabinet->fresh());

        $this->assertTrue($result['success'] ?? false, json_encode($result, JSON_UNESCAPED_UNICODE));
        $this->assertNull(Auth::id());
    }

    private function setupSchema(): void
    {
        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('name')->nullable();
                $table->string('surname')->nullable();
                $table->string('email')->unique();
                $table->timestamp('email_verified_at')->nullable();
                $table->string('password');
                $table->rememberToken();
                $table->unsignedBigInteger('selected_wb_cabinet_id')->nullable();
                $table->timestamps();
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

        if (! Schema::hasTable('wb_price_calc_v2_settings')) {
            Schema::create('wb_price_calc_v2_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('cabinet_id')->index();
                $table->string('maintenance_type')->default('transfer');
                $table->string('buyout_scope')->default('cabinet');
                $table->boolean('use_localization_index')->default(false);
                $table->boolean('use_storage')->default(false);
                $table->boolean('use_irp')->default(false);
                $table->string('commission_source')->default('fbs');
                $table->string('acquiring_source')->default('manual');
                $table->boolean('hide_sizes')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('wb_price_calc_v3_data')) {
            Schema::create('wb_price_calc_v3_data', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('cabinet_id')->index();
                $table->string('brand')->nullable();
                $table->string('subject_name')->nullable();
                $table->string('vendor_code')->nullable();
                $table->string('size')->nullable();
                $table->string('barcode')->nullable();
                $table->unsignedBigInteger('nm_id')->nullable();
                $table->decimal('volume_liters', 10, 3)->nullable();
                $table->decimal('extra_liters', 10, 3)->nullable();
                $table->decimal('cost_price', 12, 2)->nullable();
                $table->decimal('margin_percent', 6, 2)->nullable();
                $table->decimal('fulfillment_fee', 12, 2)->nullable();
                $table->decimal('maintenance_percent', 6, 2)->nullable();
                $table->decimal('stop_price', 12, 2)->nullable();
                $table->decimal('avg_base_logistics', 12, 2)->nullable();
                $table->decimal('avg_extra_liter_logistics', 12, 2)->nullable();
                $table->decimal('localization_index', 8, 4)->default(1);
                $table->decimal('avg_logistics', 12, 2)->nullable();
                $table->decimal('reverse_logistics_cost_gt_1_0_l', 12, 2)->nullable();
                $table->decimal('reverse_logistics_cost_0_801_1_0_l', 12, 2)->nullable();
                $table->decimal('reverse_logistics_cost_0_601_0_8_l', 12, 2)->nullable();
                $table->decimal('reverse_logistics_cost_0_401_0_6_l', 12, 2)->nullable();
                $table->decimal('reverse_logistics_cost_0_201_0_4_l', 12, 2)->nullable();
                $table->decimal('reverse_logistics_cost_0_001_0_2_l', 12, 2)->nullable();
                $table->decimal('return_rate_gt_1_1_l', 8, 4)->nullable();
                $table->decimal('return_rate_0_801_1_0_l', 8, 4)->nullable();
                $table->decimal('return_rate_0_601_0_8_l', 8, 4)->nullable();
                $table->decimal('return_rate_0_401_0_6_l', 8, 4)->nullable();
                $table->decimal('return_rate_0_201_0_4_l', 8, 4)->nullable();
                $table->decimal('return_rate_0_001_0_2_l', 8, 4)->nullable();
                $table->decimal('return_cost', 12, 2)->nullable();
                $table->decimal('buyout_percent', 6, 2)->nullable();
                $table->decimal('total_logistics', 12, 2)->nullable();
                $table->decimal('storage_cost', 12, 2)->nullable();
                $table->unsignedInteger('sales_count')->nullable();
                $table->decimal('storage_per_sale', 12, 2)->nullable();
                $table->decimal('advertising_percent', 6, 2)->nullable();
                $table->decimal('wb_commission_percent', 6, 2)->nullable();
                $table->decimal('options_constructor_percent_sales', 6, 2)->nullable();
                $table->decimal('options_constructor_percent_transfer', 6, 2)->nullable();
                $table->decimal('acquiring_percent', 6, 2)->nullable();
                $table->decimal('tax_percent', 6, 2)->nullable();
                $table->decimal('maintenance_percent_sales', 6, 2)->nullable();
                $table->decimal('irp', 8, 4)->nullable();
                $table->decimal('commission_plus_acquiring', 6, 2)->nullable();
                $table->decimal('standard_discount_percent', 6, 2)->nullable();
                $table->decimal('promotion_percent', 6, 2)->nullable();
                $table->decimal('min_price_promo', 12, 3)->nullable();
                $table->decimal('standard_price', 12, 2)->nullable();
                $table->decimal('price_before_discount', 12, 2)->nullable();
                $table->softDeletes();
                $table->timestamps();
            });
        }
    }
}
