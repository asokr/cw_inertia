<?php

namespace Tests\Feature\Web\Subscriber\Wb;

use App\Models\Subscribers\Subscribers;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\Subscribers\Wb\PriceCalculation\PriceCalculationCabinets;
use App\Models\Subscribers\Wb\PriceCalculation\PriceCalculationV2Data;
use App\Models\Subscribers\Wb\Repricer\RepricerCabinets;
use App\Models\User;
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
        $this->get('/panel/wb/promocalculator')->assertUnauthorized();
    }

    public function test_user_without_permission_cannot_access_index(): void
    {
        $user = $this->createSubscriberUser();

        $this->actingAs($user)
            ->get('/panel/wb/promocalculator')
            ->assertForbidden();
    }

    public function test_subscriber_with_permission_can_access_index(): void
    {
        $user = $this->createSubscriberUser(withPromoPermission: true, withPriceCalcPermission: true);

        $this->actingAs($user)
            ->get('/panel/wb/promocalculator')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Wb/PromoCalculator/Index')
                ->has('priceCalcCabinets')
                ->has('repricerCabinets'));
    }

    public function test_index_lists_owned_price_calc_cabinets(): void
    {
        $user = $this->createSubscriberUser(withPromoPermission: true, withPriceCalcPermission: true);
        $cabinet = $this->createPriceCalcCabinet($user, 'Promo Price Cabinet');

        $this->actingAs($user)
            ->get('/panel/wb/promocalculator')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('priceCalcCabinets.0.id', $cabinet->id)
                ->where('priceCalcCabinets.0.name', 'Promo Price Cabinet'));
    }

    public function test_upload_requires_xlsx_file(): void
    {
        $user = $this->createSubscriberUser(withPromoPermission: true);

        $this->actingAs($user)
            ->postJson('/panel/wb/promocalculator/upload', [])
            ->assertStatus(422);
    }

    public function test_calculate_forbidden_for_foreign_price_calc_cabinet(): void
    {
        $owner = $this->createSubscriberUser(withPromoPermission: true, withPriceCalcPermission: true);
        $intruder = $this->createSubscriberUser(withPromoPermission: true, withPriceCalcPermission: true);
        $cabinet = $this->createPriceCalcCabinet($owner, 'Foreign');
        $filePath = $this->createPromoReportFile([
            ['ART-1', 1000, 111111, 1200, 5, 2, 10],
            ['ART-2', 1100, 222222, 1300, 3, 1, 12],
        ]);

        $this->actingAs($intruder)
            ->postJson('/panel/wb/promocalculator/calculate', [
                'file' => $filePath,
                'cabinet_id' => $cabinet->id,
            ])
            ->assertForbidden();
    }

    public function test_calculate_returns_results_for_owner(): void
    {
        $user = $this->createSubscriberUser(withPromoPermission: true, withPriceCalcPermission: true);
        $cabinet = $this->createPriceCalcCabinet($user, 'Calc Cabinet');
        $this->seedPriceCalcData($cabinet->id);

        $filePath = $this->createPromoReportFile([
            ['ART-1', 1000, 111111, 1200, 5, 2, 10],
            ['ART-2', 1100, 222222, 1300, 3, 1, 12],
        ]);

        $this->actingAs($user)
            ->postJson('/panel/wb/promocalculator/calculate', [
                'file' => $filePath,
                'cabinet_id' => $cabinet->id,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data');
    }

    public function test_repricer_forbidden_for_foreign_cabinet(): void
    {
        $owner = $this->createSubscriberUser(withPromoPermission: true, withRepricerPermission: true);
        $intruder = $this->createSubscriberUser(withPromoPermission: true, withRepricerPermission: true);
        $cabinet = $this->createRepricerCabinet($owner, 'Foreign Repricer');

        $this->actingAs($intruder)
            ->postJson('/panel/wb/promocalculator/repricer', [
                'cabinet_id' => $cabinet->id,
                'data' => [
                    ['nm_id' => 111111, 'plan_price' => 1000],
                ],
                'dates' => [
                    'start' => now()->addDay()->format('Y-m-d H:i:s'),
                    'end' => now()->addDays(2)->format('Y-m-d H:i:s'),
                ],
            ])
            ->assertForbidden();
    }

    public function test_repricer_validates_named_payload_fields(): void
    {
        $user = $this->createSubscriberUser(withPromoPermission: true, withRepricerPermission: true);
        $cabinet = $this->createRepricerCabinet($user, 'Repricer');

        $this->actingAs($user)
            ->postJson('/panel/wb/promocalculator/repricer', [
                'cabinet_id' => $cabinet->id,
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

    public function test_upload_accepts_xlsx_and_returns_path(): void
    {
        $user = $this->createSubscriberUser(withPromoPermission: true);
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

    private function createPriceCalcCabinet(User $user, string $name): PriceCalculationCabinets
    {
        return PriceCalculationCabinets::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'apikey' => 'test-api-key',
        ]);
    }

    private function createRepricerCabinet(User $user, string $name): RepricerCabinets
    {
        return RepricerCabinets::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'apikey' => 'test-api-key',
        ]);
    }

    private function seedPriceCalcData(int $cabinetId): void
    {
        foreach ([111111, 222222] as $nmId) {
            PriceCalculationV2Data::query()->create([
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

        $path = 'wb/promocalculator/test-report.xlsx';
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
        if (! Schema::hasTable('wb_price_cabinets')) {
            Schema::create('wb_price_cabinets', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('name');
                $table->text('apikey')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('wb_price_calc_v2_data')) {
            Schema::create('wb_price_calc_v2_data', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('cabinet_id')->index();
                $table->unsignedBigInteger('nm_id')->nullable()->index();
                $table->decimal('cost_price', 12, 2)->default(0);
                $table->decimal('fulfillment_fee', 12, 2)->default(0);
                $table->decimal('wb_commission_percent', 6, 2)->default(0);
                $table->decimal('total_logistics', 12, 2)->default(0);
                $table->decimal('min_price_promo', 12, 3)->default(0);
                $table->decimal('tax_percent', 6, 2)->default(0);
                $table->decimal('advertising_percent', 6, 2)->default(0);
                $table->decimal('acquiring_percent', 6, 2)->default(0);
                $table->decimal('maintenance_percent', 6, 2)->default(0);
                $table->softDeletes();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('wb_repricer_cabinets')) {
            Schema::create('wb_repricer_cabinets', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('name');
                $table->text('apikey')->nullable();
                $table->integer('error_code')->nullable();
                $table->text('error_message')->nullable();
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