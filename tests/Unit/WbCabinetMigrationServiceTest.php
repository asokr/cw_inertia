<?php

namespace Tests\Unit;

use App\Models\Subscribers\Subscribers;
use App\Models\Subscribers\Wb\Feedbacks\FeedbacksClients;
use App\Models\Subscribers\Wb\Feedbacks\Review;
use App\Models\Subscribers\Wb\Feedbacks\WbFeedbacksSettings;
use App\Models\Subscribers\Wb\PriceCalculation\PriceCalculationCabinets;
use App\Models\Subscribers\Wb\PriceCalculation\PriceCalculationV3Data;
use App\Models\Subscribers\Wb\Profitability\Item as ProfitabilityItem;
use App\Models\Subscribers\Wb\Profitability\ProfitabilityCabinet;
use App\Models\Subscribers\Wb\Profitability\Report as ProfitabilityReport;
use App\Models\Subscribers\Wb\WbCabinet;
use App\Models\User;
use App\Services\Subscriber\Wb\WbCabinetMigrationService;
use App\Services\Subscriber\Wb\WbCabinetService;
use App\Services\Wb\WbCabinetApiKeyValidator;
use App\Support\Wb\WbCabinetServiceRegistry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Web\Auth\WebAuthTestCase;

class WbCabinetMigrationServiceTest extends WebAuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupMigrationSchema();
    }

    public function test_needs_migration_when_legacy_cabinets_exist(): void
    {
        $user = $this->makeUserWithSubscriber();
        PriceCalculationCabinets::query()->create([
            'user_id' => $user->id,
            'name' => 'Old price',
            'apikey' => 'legacy-key-1',
            'is_migrated' => false,
        ]);

        $this->assertTrue($this->migrationService()->needsMigration($user));
    }

    public function test_migrate_rewrites_child_ids_per_service_without_collision(): void
    {
        $user = $this->makeUserWithSubscriber();

        $oldPrice = PriceCalculationCabinets::query()->create([
            'user_id' => $user->id,
            'name' => 'Price A',
            'apikey' => 'price-key',
            'is_migrated' => false,
        ]);

        $oldFeedbacks = FeedbacksClients::query()->create([
            'subscriber_id' => $user->subscriber->id,
            'name' => 'Feedbacks A',
            'apikey' => 'fb-key',
            'bot_status' => true,
            'ai_status' => false,
            'is_migrated' => false,
        ]);

        PriceCalculationV3Data::query()->create([
            'cabinet_id' => $oldPrice->id,
            'nm_id' => 111,
            'cost_price' => 10,
        ]);

        Review::query()->create([
            'cabinet_id' => $oldFeedbacks->id,
            'product_id' => 222,
            'rating' => 5,
            'content' => 'ok',
        ]);

        $wb = WbCabinet::query()->create([
            'user_id' => $user->id,
            'name' => 'Unified',
            'apikey' => 'new-key',
            'api_key_hash' => hash('sha256', 'new-key'),
        ]);

        $this->migrationService()->migrate($user, [
            [
                'wb_cabinet_id' => $wb->id,
                'mappings' => [
                    ['service' => 'price_calc', 'old_cabinet_id' => $oldPrice->id],
                    ['service' => 'feedbacks', 'old_cabinet_id' => $oldFeedbacks->id],
                ],
            ],
        ]);

        $this->assertSame((int) $wb->id, (int) PriceCalculationV3Data::query()->first()->cabinet_id);
        $this->assertSame((int) $wb->id, (int) Review::query()->first()->cabinet_id);

        $oldPrice->refresh();
        $oldFeedbacks->refresh();
        $this->assertTrue((bool) $oldPrice->is_migrated);
        $this->assertTrue((bool) $oldFeedbacks->is_migrated);
        $this->assertSame((int) $wb->id, (int) $oldPrice->wb_cabinet_id);
        $this->assertSame((int) $wb->id, (int) $oldFeedbacks->wb_cabinet_id);

        $settings = WbFeedbacksSettings::query()->where('cabinet_id', $wb->id)->first();
        $this->assertNotNull($settings);
        $this->assertTrue((bool) $settings->bot_status);

        $this->assertFalse($this->migrationService()->needsMigration($user->fresh()));
    }

    public function test_rejects_two_old_cabinets_of_same_service_on_one_wb_cabinet(): void
    {
        $user = $this->makeUserWithSubscriber();

        $a = PriceCalculationCabinets::query()->create([
            'user_id' => $user->id,
            'name' => 'A',
            'apikey' => 'a',
            'is_migrated' => false,
        ]);
        $b = PriceCalculationCabinets::query()->create([
            'user_id' => $user->id,
            'name' => 'B',
            'apikey' => 'b',
            'is_migrated' => false,
        ]);

        $wb = WbCabinet::query()->create([
            'user_id' => $user->id,
            'name' => 'Unified',
            'apikey' => 'new-key',
            'api_key_hash' => hash('sha256', 'new-key-2'),
        ]);

        $this->expectException(ValidationException::class);

        $this->migrationService()->migrate($user, [
            [
                'wb_cabinet_id' => $wb->id,
                'mappings' => [
                    ['service' => 'price_calc', 'old_cabinet_id' => $a->id],
                    ['service' => 'price_calc', 'old_cabinet_id' => $b->id],
                ],
            ],
        ]);
    }

    public function test_can_delete_legacy_cabinet_with_children_instead_of_mapping(): void
    {
        $user = $this->makeUserWithSubscriber();

        $keep = PriceCalculationCabinets::query()->create([
            'user_id' => $user->id,
            'name' => 'Keep',
            'apikey' => 'keep-key',
            'is_migrated' => false,
        ]);
        $drop = PriceCalculationCabinets::query()->create([
            'user_id' => $user->id,
            'name' => 'Drop',
            'apikey' => 'drop-key',
            'is_migrated' => false,
        ]);

        PriceCalculationV3Data::query()->create([
            'cabinet_id' => $keep->id,
            'nm_id' => 1,
            'cost_price' => 10,
        ]);
        PriceCalculationV3Data::query()->create([
            'cabinet_id' => $drop->id,
            'nm_id' => 2,
            'cost_price' => 20,
        ]);

        $wb = WbCabinet::query()->create([
            'user_id' => $user->id,
            'name' => 'Unified',
            'apikey' => 'new-key',
            'api_key_hash' => hash('sha256', 'new-key-delete'),
        ]);

        $this->migrationService()->migrate(
            $user,
            [
                [
                    'wb_cabinet_id' => $wb->id,
                    'mappings' => [
                        ['service' => 'price_calc', 'old_cabinet_id' => $keep->id],
                    ],
                ],
            ],
            [
                ['service' => 'price_calc', 'old_cabinet_id' => $drop->id],
            ]
        );

        $this->assertDatabaseMissing('wb_price_cabinets', ['id' => $drop->id]);
        $this->assertSame(0, PriceCalculationV3Data::query()->where('cabinet_id', $drop->id)->count());
        $this->assertSame(1, PriceCalculationV3Data::query()->where('cabinet_id', $wb->id)->count());
        $this->assertFalse($this->migrationService()->needsMigration($user->fresh()));
    }

    public function test_profitability_rewrite_frees_unique_cabinet_id_slot(): void
    {
        $user = $this->makeUserWithSubscriber();

        // Distinct numeric ids: legacy cabinet ≠ target wb cabinet (shared autoincrement
        // would make both id=1 and break unique(cabinet_id) while seeding).
        Schema::disableForeignKeyConstraints();
        ProfitabilityCabinet::query()->insert([
            'id' => 12,
            'user_id' => $user->id,
            'name' => 'Legacy profit',
            'apikey' => 'profit-key',
            'is_migrated' => false,
            'migrated_at' => null,
            'wb_cabinet_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        WbCabinet::query()->insert([
            'id' => 8,
            'user_id' => $user->id,
            'name' => 'Unified',
            'apikey' => 'new-key',
            'api_key_hash' => hash('sha256', 'new-key-profit-collision'),
            'error_code' => null,
            'error_message' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Schema::enableForeignKeyConstraints();

        // Occupies unique cabinet_id = wb.id (orphan / other legacy id space) — production 500 case.
        $blocker = ProfitabilityReport::query()->create([
            'cabinet_id' => 8,
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-31',
            'sales_amount' => 1,
        ]);
        ProfitabilityItem::query()->create([
            'report_id' => $blocker->id,
            'nm_id' => 1,
            'quantity' => 1,
        ]);

        $source = ProfitabilityReport::query()->create([
            'cabinet_id' => 12,
            'date_from' => '2026-02-01',
            'date_to' => '2026-02-28',
            'sales_amount' => 99,
        ]);
        ProfitabilityItem::query()->create([
            'report_id' => $source->id,
            'nm_id' => 2,
            'quantity' => 5,
        ]);

        $this->migrationService()->migrate($user, [
            [
                'wb_cabinet_id' => 8,
                'mappings' => [
                    ['service' => 'profitability', 'old_cabinet_id' => 12],
                ],
            ],
        ]);

        $this->assertDatabaseMissing('wb_profitability_reports', ['id' => $blocker->id]);
        $this->assertDatabaseMissing('wb_profitability_items', ['report_id' => $blocker->id]);

        $source->refresh();
        $this->assertSame(8, (int) $source->cabinet_id);
        $this->assertSame(1, ProfitabilityItem::query()->where('report_id', $source->id)->count());
        $this->assertSame(1, ProfitabilityReport::query()->where('cabinet_id', 8)->count());

        $this->assertDatabaseHas('wb_profitability_cabinets', [
            'id' => 12,
            'is_migrated' => 1,
            'wb_cabinet_id' => 8,
        ]);
    }

    public function test_profitability_two_phase_preserves_both_reports_when_ids_overlap(): void
    {
        $user = $this->makeUserWithSubscriber();

        // Shared numeric id between wb cabinet and legacy profitability cabinet.
        $sharedId = 8;

        Schema::disableForeignKeyConstraints();
        WbCabinet::query()->insert([
            'id' => $sharedId,
            'user_id' => $user->id,
            'name' => 'WB shared id',
            'apikey' => 'key-a',
            'api_key_hash' => hash('sha256', 'key-a-two-phase'),
            'error_code' => null,
            'error_message' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        WbCabinet::query()->insert([
            'id' => 15,
            'user_id' => $user->id,
            'name' => 'WB other',
            'apikey' => 'key-b',
            'api_key_hash' => hash('sha256', 'key-b-two-phase'),
            'error_code' => null,
            'error_message' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        ProfitabilityCabinet::query()->insert([
            'id' => 5,
            'user_id' => $user->id,
            'name' => 'Profit A',
            'apikey' => 'pa',
            'is_migrated' => false,
            'migrated_at' => null,
            'wb_cabinet_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // Legacy B has the same id as the first wb cabinet.
        ProfitabilityCabinet::query()->insert([
            'id' => $sharedId,
            'user_id' => $user->id,
            'name' => 'Profit B',
            'apikey' => 'pb',
            'is_migrated' => false,
            'migrated_at' => null,
            'wb_cabinet_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Schema::enableForeignKeyConstraints();

        $reportA = ProfitabilityReport::query()->create([
            'cabinet_id' => 5,
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-31',
            'sales_amount' => 10,
        ]);
        $reportB = ProfitabilityReport::query()->create([
            'cabinet_id' => $sharedId,
            'date_from' => '2026-02-01',
            'date_to' => '2026-02-28',
            'sales_amount' => 20,
        ]);

        // Naive single-pass would rewrite A→8 while B's report still sits at cabinet_id=8.
        $this->migrationService()->migrate($user, [
            [
                'wb_cabinet_id' => $sharedId,
                'mappings' => [
                    ['service' => 'profitability', 'old_cabinet_id' => 5],
                ],
            ],
            [
                'wb_cabinet_id' => 15,
                'mappings' => [
                    ['service' => 'profitability', 'old_cabinet_id' => $sharedId],
                ],
            ],
        ]);

        $reportA->refresh();
        $reportB->refresh();
        $this->assertSame($sharedId, (int) $reportA->cabinet_id);
        $this->assertSame(15, (int) $reportB->cabinet_id);
        $this->assertSame(2, ProfitabilityReport::query()->count());
    }

    public function test_profitability_does_not_steal_foreign_migrated_report(): void
    {
        $owner = $this->makeUserWithSubscriber();
        $other = $this->makeUserWithSubscriber();

        $sharedId = 8;

        Schema::disableForeignKeyConstraints();
        WbCabinet::query()->insert([
            'id' => $sharedId,
            'user_id' => $owner->id,
            'name' => 'Owner WB',
            'apikey' => 'owner-key',
            'api_key_hash' => hash('sha256', 'owner-key'),
            'error_code' => null,
            'error_message' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // Owner's legacy row uses a different PK; report already points at wb_cabinets.id.
        ProfitabilityCabinet::query()->insert([
            'id' => 100,
            'user_id' => $owner->id,
            'name' => 'Owner legacy migrated',
            'apikey' => 'owner-legacy',
            'is_migrated' => true,
            'migrated_at' => now(),
            'wb_cabinet_id' => $sharedId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // Other user: unmigrated legacy cabinet with the same numeric id as owner's wb.
        ProfitabilityCabinet::query()->insert([
            'id' => $sharedId,
            'user_id' => $other->id,
            'name' => 'Other legacy same id',
            'apikey' => 'other-legacy',
            'is_migrated' => false,
            'migrated_at' => null,
            'wb_cabinet_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        WbCabinet::query()->insert([
            'id' => 50,
            'user_id' => $other->id,
            'name' => 'Other unified',
            'apikey' => 'other-key',
            'api_key_hash' => hash('sha256', 'other-key'),
            'error_code' => null,
            'error_message' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Schema::enableForeignKeyConstraints();

        $foreignReport = ProfitabilityReport::query()->create([
            'cabinet_id' => $sharedId,
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-31',
            'sales_amount' => 50,
        ]);

        $this->migrationService()->migrate($other, [
            [
                'wb_cabinet_id' => 50,
                'mappings' => [
                    ['service' => 'profitability', 'old_cabinet_id' => $sharedId],
                ],
            ],
        ]);

        $foreignReport->refresh();
        $this->assertSame($sharedId, (int) $foreignReport->cabinet_id);
        $this->assertDatabaseHas('wb_profitability_cabinets', [
            'id' => $sharedId,
            'user_id' => $other->id,
            'is_migrated' => 1,
            'wb_cabinet_id' => 50,
        ]);
        // Foreign report must not be rewritten onto the other user's cabinet.
        $this->assertSame(0, ProfitabilityReport::query()->where('cabinet_id', 50)->count());
    }

    private function migrationService(): WbCabinetMigrationService
    {
        $validator = $this->createMock(WbCabinetApiKeyValidator::class);
        $cabinets = new WbCabinetService($validator);

        return new WbCabinetMigrationService(new WbCabinetServiceRegistry(), $cabinets);
    }

    private function makeUserWithSubscriber(): User
    {
        if (! Schema::hasColumn('users', 'selected_wb_cabinet_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedBigInteger('selected_wb_cabinet_id')->nullable();
            });
        }

        $user = User::query()->create([
            'name' => 'Test',
            'email' => 'wb-migrate-'.uniqid().'@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        Subscribers::query()->create([
            'user_id' => $user->id,
            'status' => 1,
        ]);

        return $user->fresh(['subscriber']);
    }

    private function setupMigrationSchema(): void
    {
        Schema::dropIfExists('wb_profitability_items');
        Schema::dropIfExists('wb_profitability_reports');
        Schema::dropIfExists('wb_profitability_cabinets');
        Schema::dropIfExists('wb_feedbacks_reviews');
        Schema::dropIfExists('wb_price_calc_v3_data');
        Schema::dropIfExists('wb_feedbacks_settings');
        Schema::dropIfExists('wb_cabinets');
        Schema::dropIfExists('subs_wb_feedbacks_clients');
        Schema::dropIfExists('wb_price_cabinets');

        if (! Schema::hasColumn('users', 'selected_wb_cabinet_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedBigInteger('selected_wb_cabinet_id')->nullable();
            });
        }

        Schema::create('wb_cabinets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('name');
            $table->text('apikey');
            $table->string('api_key_hash', 64)->nullable();
            $table->integer('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('wb_feedbacks_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cabinet_id')->unique();
            $table->string('brands')->nullable();
            $table->boolean('bot_status')->default(false);
            $table->boolean('ai_status')->default(false);
            $table->json('ai_ratings')->nullable();
            $table->string('review_type')->nullable();
            $table->timestamps();
        });

        Schema::create('wb_price_cabinets', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_migrated')->default(false);
            $table->timestamp('migrated_at')->nullable();
            $table->unsignedBigInteger('wb_cabinet_id')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->string('name');
            $table->text('apikey')->nullable();
            $table->timestamps();
        });

        Schema::create('subs_wb_feedbacks_clients', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_migrated')->default(false);
            $table->timestamp('migrated_at')->nullable();
            $table->unsignedBigInteger('wb_cabinet_id')->nullable();
            $table->unsignedBigInteger('subscriber_id');
            $table->string('name');
            $table->string('brands')->nullable();
            $table->text('apikey')->nullable();
            $table->boolean('bot_status')->default(false);
            $table->boolean('ai_status')->default(false);
            $table->json('ai_ratings')->nullable();
            $table->string('review_type')->nullable();
            $table->timestamps();
        });

        Schema::create('wb_price_calc_v3_data', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cabinet_id');
            $table->unsignedBigInteger('nm_id')->nullable();
            $table->decimal('cost_price', 12, 2)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('wb_feedbacks_reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cabinet_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->integer('rating')->nullable();
            $table->text('content')->nullable();
            $table->timestamps();
        });

        Schema::create('wb_profitability_cabinets', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_migrated')->default(false);
            $table->timestamp('migrated_at')->nullable();
            $table->unsignedBigInteger('wb_cabinet_id')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->string('name');
            $table->text('apikey')->nullable();
            $table->timestamps();
        });

        Schema::create('wb_profitability_reports', function (Blueprint $table) {
            $table->id();
            // Mirrors production unique: one report row per cabinet_id.
            $table->unsignedBigInteger('cabinet_id')->unique();
            $table->date('date_from')->nullable();
            $table->date('date_to')->nullable();
            $table->decimal('sales_amount', 14, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('wb_profitability_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('report_id')->index();
            $table->unsignedBigInteger('nm_id')->nullable();
            $table->integer('quantity')->default(0);
            $table->timestamps();
        });
    }
}
