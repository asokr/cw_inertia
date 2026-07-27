<?php

namespace Tests\Unit;

use App\Models\Subscribers\Subscribers;
use App\Models\Subscribers\Wb\Feedbacks\FeedbacksClients;
use App\Models\Subscribers\Wb\Feedbacks\Review;
use App\Models\Subscribers\Wb\Feedbacks\WbFeedbacksSettings;
use App\Models\Subscribers\Wb\PriceCalculation\PriceCalculationCabinets;
use App\Models\Subscribers\Wb\PriceCalculation\PriceCalculationV3Data;
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
    }
}
