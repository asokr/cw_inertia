<?php

namespace Tests\Feature\Console;

use App\Enums\Credits\CreditBillingMode;
use App\Enums\Credits\CreditLedgerType;
use App\Enums\Credits\CreditServiceCode;
use App\Models\Credits\CreditAccount;
use App\Models\Credits\CreditLedger;
use App\Models\Credits\CreditService;
use App\Models\Subscribers\Subscribers;
use App\Models\Subscribers\SubscribersPlans;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\Subscribers\Wb\Feedbacks\WbFeedbacksSettings;
use App\Models\Subscribers\Wb\WbCabinet;
use App\Models\User;
use App\Support\Wb\FeedbacksRuntimeCabinetResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Web\Auth\WebAuthTestCase;
use Tests\Support\CreatesCreditBillingSchema;

class SubscriberWbFeedbacksAnswerCreditsTest extends WebAuthTestCase
{
    use CreatesCreditBillingSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setupCommandSchema();
        $this->setupCreditBillingSchema();
        $this->seedFeedbackAnswerPrice(1);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::firstOrCreate([
            'name' => 'subscriber wb feedbacks',
            'guard_name' => 'web',
        ]);
    }

    public function test_command_skips_ai_when_credits_are_not_enough_even_if_old_limit_remains(): void
    {
        $user = $this->createSubscriberWithPlan();
        $this->createAiCabinet($user);
        $this->grantCredits($user, 0);

        $this->mock(FeedbacksRuntimeCabinetResolver::class, function ($mock): void {
            $mock->shouldReceive('forAi')->never();
            $mock->shouldReceive('forBot')->once()->andReturn(new Collection);
        });

        $this->artisan('subscriber:wb-feedbacks-answer')->assertSuccessful();

        $this->assertSame(0, CreditLedger::query()->where('user_id', $user->id)->where('type', CreditLedgerType::Capture)->count());
        $this->assertSame(0, CreditAccount::query()->where('user_id', $user->id)->first()?->available());
    }

    public function test_command_uses_credits_instead_of_old_monthly_limit(): void
    {
        $user = $this->createSubscriberWithPlan();
        $this->createAiCabinet($user);
        $this->grantCredits($user, 10);

        $this->mock(FeedbacksRuntimeCabinetResolver::class, function ($mock): void {
            $mock->shouldReceive('forAi')->once()->andReturn(new Collection);
            $mock->shouldReceive('forBot')->once()->andReturn(new Collection);
        });

        $this->artisan('subscriber:wb-feedbacks-answer')->assertSuccessful();

        $this->assertSame(10, CreditAccount::query()->where('user_id', $user->id)->first()?->available());
        $this->assertSame(0, CreditLedger::query()->where('user_id', $user->id)->where('type', CreditLedgerType::Capture)->count());
    }

    private function createSubscriberWithPlan(): User
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
        ]);
        $user->assignRole('Подписчик');
        $user->givePermissionTo('subscriber wb feedbacks');

        $subscriber = Subscribers::query()->create([
            'user_id' => $user->id,
            'status' => 1,
        ]);

        $plan = SubscribersPlans::query()->create([
            'name' => 'Feedbacks plan',
            'price' => 0,
            'duration' => 30,
            'permissions' => ['subscriber wb feedbacks'],
            'status' => 1,
        ]);

        SubscribersSubscriptions::query()->create([
            'subscribers_id' => $subscriber->id,
            'plan_id' => $plan->id,
            'status' => 1,
        ]);

        return $user;
    }

    private function createAiCabinet(User $user): WbCabinet
    {
        $cabinet = WbCabinet::query()->create([
            'user_id' => $user->id,
            'name' => 'AI Cabinet',
            'apikey' => 'test-api-key',
            'api_key_hash' => hash('sha256', 'test-api-key-ai'),
        ]);

        WbFeedbacksSettings::query()->create([
            'cabinet_id' => $cabinet->id,
            'brands' => '',
            'bot_status' => false,
            'ai_status' => true,
            'ai_ratings' => [5],
        ]);

        return $cabinet;
    }

    private function seedFeedbackAnswerPrice(int $amount): void
    {
        CreditService::query()->updateOrCreate(
            ['code' => CreditServiceCode::FeedbackAnswer->value],
            [
                'name' => CreditServiceCode::FeedbackAnswer->label(),
                'billing_mode' => CreditBillingMode::Fixed,
                'amount' => $amount,
                'sort_order' => 20,
                'is_active' => true,
            ],
        );
    }

    private function grantCredits(User $user, int $amount): void
    {
        CreditAccount::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'subscription_balance' => 0,
                'purchased_balance' => $amount,
                'subscription_held' => 0,
                'purchased_held' => 0,
            ],
        );
    }

    private function setupCommandSchema(): void
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
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('wb_feedbacks_settings')) {
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
        }

    }
}
