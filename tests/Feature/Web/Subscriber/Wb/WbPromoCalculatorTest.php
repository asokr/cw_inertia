<?php

namespace Tests\Feature\Web\Subscriber\Wb;

use App\Models\Subscribers\Subscribers;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\Subscribers\Wb\PriceCalculation\PriceCalculationV3Data;
use App\Models\Subscribers\Wb\WbCabinet;
use App\Models\User;
use App\Services\Subscriber\Wb\WbPromoCalculatorService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Web\Auth\WebAuthTestCase;

class WbPromoCalculatorTest extends WebAuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setupPromoCalculatorSchema();
        Storage::fake('public');

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach ([
            'subscriber wb promo calculator',
            'subscriber wb price calculator',
            'subscriber wb repricer',
        ] as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }
    }

    public function test_guest_cannot_access_index(): void
    {
        $this->get('/panel/wb/promocalculator')->assertRedirect();
    }

    public function test_user_without_permission_cannot_access_index(): void
    {
        $user = $this->createSubscriberUser();

        $this->actingAs($user)
            ->get('/panel/wb/promocalculator')
            ->assertForbidden();
    }

    public function test_subscriber_without_cabinet_sees_no_cabinet_page(): void
    {
        $user = $this->createSubscriberUser(withPromoPermission: true);

        $this->actingAs($user)
            ->get('/panel/wb/promocalculator')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Wb/Shared/NoCabinet')
                ->where('toolName', 'Рентабельность акций'));
    }

    public function test_subscriber_with_permission_and_cabinet_can_access_index(): void
    {
        $user = $this->createSubscriberUser(withPromoPermission: true, withRepricerPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Promo Cabinet');

        $this->actingAs($user)
            ->get('/panel/wb/promocalculator')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Wb/PromoCalculator/Index')
                ->where('cabinet.id', $cabinet->id)
                ->where('cabinet.name', 'Promo Cabinet')
                ->where('canUseRepricer', true)
                ->missing('priceCalcCabinets')
                ->missing('repricerCabinets'));
    }

    public function test_upload_requires_xlsx_file(): void
    {
        $user = $this->createSubscriberUser(withPromoPermission: true);
        $this->createUnifiedCabinet($user, 'Upload Cabinet');

        $this->actingAs($user)
            ->postJson('/panel/wb/promocalculator/upload', [])
            ->assertStatus(422);
    }

    public function test_calculate_requires_selected_cabinet(): void
    {
        $user = $this->createSubscriberUser(withPromoPermission: true);
        $filePath = $this->createPromoReportFile([
            ['ART-1', 1000, 111111, 1200, 5, 2, 10],
            ['ART-2', 1100, 222222, 1300, 3, 1, 12],
        ]);

        $this->actingAs($user)
            ->postJson('/panel/wb/promocalculator/calculate', [
                'file' => $filePath,
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_calculate_returns_results_for_selected_cabinet_v3_data(): void
    {
        $user = $this->createSubscriberUser(withPromoPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Calc Cabinet');
        $this->seedPriceCalcV3Data($cabinet->id);

        $filePath = $this->createPromoReportFile([
            ['ART-1', 1000, 111111, 1200, 5, 2, 10],
            ['ART-2', 1100, 222222, 1300, 3, 1, 12],
        ]);

        $this->actingAs($user)
            ->postJson('/panel/wb/promocalculator/calculate', [
                'file' => $filePath,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data');
    }

    public function test_calculate_ignores_client_cabinet_id_and_uses_selected(): void
    {
        $user = $this->createSubscriberUser(withPromoPermission: true);
        $ownCabinet = $this->createUnifiedCabinet($user, 'Own');
        $this->seedPriceCalcV3Data($ownCabinet->id);

        $otherUser = $this->createSubscriberUser(withPromoPermission: true);
        $foreignCabinet = $this->createUnifiedCabinet($otherUser, 'Foreign');
        $this->seedPriceCalcV3Data($foreignCabinet->id, nmIds: [999001, 999002]);

        $filePath = $this->createPromoReportFile([
            ['ART-1', 1000, 111111, 1200, 5, 2, 10],
            ['ART-2', 1100, 222222, 1300, 3, 1, 12],
        ]);

        $this->actingAs($user)
            ->postJson('/panel/wb/promocalculator/calculate', [
                'file' => $filePath,
                'cabinet_id' => $foreignCabinet->id,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data');
    }

    public function test_repricer_requires_selected_cabinet(): void
    {
        $user = $this->createSubscriberUser(withPromoPermission: true, withRepricerPermission: true);

        $this->actingAs($user)
            ->postJson('/panel/wb/promocalculator/repricer', [
                'data' => [
                    ['nm_id' => 111111, 'plan_price' => 1000],
                ],
                'dates' => [
                    'start' => now()->addDay()->format('Y-m-d H:i:s'),
                    'end' => now()->addDays(2)->format('Y-m-d H:i:s'),
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_repricer_validates_named_payload_fields(): void
    {
        $user = $this->createSubscriberUser(withPromoPermission: true, withRepricerPermission: true);
        $this->createUnifiedCabinet($user, 'Repricer');

        $this->actingAs($user)
            ->postJson('/panel/wb/promocalculator/repricer', [
                'data' => [
                    ['nm_id' => 111111],
                ],
                'dates' => [
                    'start' => now()->addDay()->format('Y-m-d H:i:s'),
                    'end' => now()->addDays(2)->format('Y-m-d H:i:s'),
                ],
            ])
            ->assertStatus(422);
    }

    public function test_repricer_creates_settings_for_selected_cabinet(): void
    {
        $user = $this->createSubscriberUser(withPromoPermission: true, withRepricerPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Repricer Cabinet');

        $this->mock(WbPromoCalculatorService::class, function ($mock) use ($cabinet): void {
            $mock->shouldReceive('sendToRepricer')
                ->once()
                ->withArgs(function ($request, WbCabinet $resolved) use ($cabinet) {
                    return (int) $resolved->id === (int) $cabinet->id
                        && (int) ($request->input('data.0.nm_id') ?? 0) === 111111;
                })
                ->andReturn(response()->json([
                    'success' => true,
                    'messages' => ['Номенклатуры переданы в репрайсер'],
                ], 200));
        });

        $this->actingAs($user)
            ->postJson('/panel/wb/promocalculator/repricer', [
                'data' => [
                    ['nm_id' => 111111, 'plan_price' => 1000],
                ],
                'dates' => [
                    'start' => now()->addDay()->format('Y-m-d\TH:i'),
                    'end' => now()->addDays(2)->format('Y-m-d\TH:i'),
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_upload_accepts_xlsx_and_returns_path(): void
    {
        $user = $this->createSubscriberUser(withPromoPermission: true);
        $this->createUnifiedCabinet($user, 'Upload');
        $tempPath = $this->createPromoReportFile([
            ['ART-1', 1000, 111111, 1200, 5, 2, 10],
            ['ART-2', 1100, 222222, 1300, 3, 1, 12],
        ]);
        $fullPath = Storage::disk('public')->path($tempPath);

        $this->actingAs($user)
            ->post('/panel/wb/promocalculator/upload', [
                'file' => new UploadedFile($fullPath, 'promo.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['file']]);
    }

    private function createSubscriberUser(
        bool $withPromoPermission = false,
        bool $withPriceCalcPermission = false,
        bool $withRepricerPermission = false,
    ): User {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
        ]);

        $user->assignRole('Подписчик');

        if ($withPromoPermission) {
            $user->givePermissionTo('subscriber wb promo calculator');
        }

        if ($withPriceCalcPermission) {
            $user->givePermissionTo('subscriber wb price calculator');
        }

        if ($withRepricerPermission) {
            $user->givePermissionTo('subscriber wb repricer');
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
            'api_key_hash' => hash('sha256', 'test-api-key-'.$name.'-'.$user->id),
        ]);

        $user->forceFill(['selected_wb_cabinet_id' => $cabinet->id])->save();

        return $cabinet;
    }

    /**
     * @param  list<int>  $nmIds
     */
    private function seedPriceCalcV3Data(int $cabinetId, array $nmIds = [111111, 222222]): void
    {
        foreach ($nmIds as $nmId) {
            PriceCalculationV3Data::query()->create([
                'cabinet_id' => $cabinetId,
                'nm_id' => $nmId,
                'cost_price' => 300,
                'fulfillment_fee' => 20,
                'wb_commission_percent' => 15,
                'total_logistics' => 50,
                'min_price_promo' => 800,
                'tax_percent' => 6,
                'advertising_percent' => 5,
                'acquiring_percent' => 2,
                'maintenance_percent' => 1,
            ]);
        }
    }

    /**
     * @param  array<int, array<int, int|string>>  $rows
     */
    private function createPromoReportFile(array $rows): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $headers = [
            'Артикул поставщика',
            'Плановая цена для акции',
            'Артикул WB',
            'Текущая розничная цена',
            'Остаток товара на складах Wb (шт.)',
            'Остаток товара на складе продавца Wb (шт.)',
            'Загружаемая скидка для участия в акции',
        ];

        $sheet->fromArray([$headers], null, 'A2');
        $rowNum = 3;

        foreach ($rows as $row) {
            $sheet->fromArray([$row], null, "A{$rowNum}");
            $rowNum++;
        }

        $path = 'wb/promocalculator/test-report-'.uniqid('', true).'.xlsx';
        $directory = dirname(Storage::disk('public')->path($path));
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $writer = new XlsxWriter($spreadsheet);
        $writer->save(Storage::disk('public')->path($path));

        return $path;
    }

    private function setupPromoCalculatorSchema(): void
    {
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'selected_wb_cabinet_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedBigInteger('selected_wb_cabinet_id')->nullable();
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

        if (! Schema::hasTable('wb_price_calc_v3_data')) {
            Schema::create('wb_price_calc_v3_data', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('cabinet_id')->index();
                $table->string('brand')->nullable();
                $table->string('subject_name')->nullable();
                $table->string('vendor_code')->nullable();
                $table->string('size')->nullable();
                $table->string('barcode')->nullable();
                $table->unsignedBigInteger('nm_id')->nullable()->index();
                $table->decimal('cost_price', 12, 2)->nullable();
                $table->decimal('fulfillment_fee', 12, 2)->nullable();
                $table->decimal('wb_commission_percent', 6, 2)->nullable();
                $table->decimal('total_logistics', 12, 2)->nullable();
                $table->decimal('min_price_promo', 12, 3)->nullable();
                $table->decimal('tax_percent', 6, 2)->nullable();
                $table->decimal('advertising_percent', 6, 2)->nullable();
                $table->decimal('acquiring_percent', 6, 2)->nullable();
                $table->decimal('maintenance_percent', 6, 2)->nullable();
                $table->softDeletes();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('wb_repricer_settings')) {
            Schema::create('wb_repricer_settings', function (Blueprint $table) {
                $table->id();
                $table->string('name')->nullable();
                $table->unsignedBigInteger('cabinet_id')->index();
                $table->unsignedBigInteger('nmID')->index();
                $table->decimal('base_value', 12, 2)->nullable();
                $table->decimal('base_discount', 8, 2)->nullable();
                $table->string('price_type')->nullable();
                $table->string('strategy')->nullable();
                $table->string('pricing_modifier_type')->nullable();
                $table->text('terms')->nullable();
                $table->unsignedTinyInteger('active')->default(0);
                $table->unsignedTinyInteger('status')->default(1);
                $table->unsignedInteger('repeats_counter')->default(0);
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
