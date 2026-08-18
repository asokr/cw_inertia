<?php

namespace Tests\Feature\Credits;

use App\Enums\Credits\CreditLedgerType;
use App\Exceptions\Credits\InvalidCreditOperationException;
use App\Models\Credits\CreditAccount;
use App\Models\Credits\CreditLedger;
use App\Models\Credits\CreditLegacyMigration;
use App\Models\Credits\CreditLegacyPlanMigration;
use App\Models\Subscribers\Subscribers;
use App\Models\Subscribers\SubscribersPlans;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\User;
use App\Services\Credits\CreditBillingService;
use App\Services\Credits\LegacyCreditMigrationService;
use App\Support\ToolLimits;
use Carbon\Carbon;
use Database\Seeders\CreditPricingSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CreatesCreditBillingSchema;
use Tests\TestCase;

class LegacyCreditMigrationServiceTest extends TestCase
{
    use CreatesCreditBillingSchema;

    private LegacyCreditMigrationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupCreditBillingSchema();
        (new CreditPricingSeeder)->run();
        $this->service = app(LegacyCreditMigrationService::class);
    }

    public function test_month_goes_to_subscription_and_extra_to_purchased(): void
    {
        $user = $this->makeSubscriber(
            month: ['ai_text_query' => 10, 'ai_image_query' => 2],
            extra: ['ai_video_query' => 3, 'feedbacks_gpt_query' => 4],
        );

        $this->service->run();

        $account = CreditAccount::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($account);
        $this->assertSame(20, $account->subscription_balance);
        $this->assertSame(16, $account->purchased_balance);

        $monthLedger = CreditLedger::query()
            ->where('user_id', $user->id)
            ->where('idempotency_key', 'migration:user:'.$user->id.':month')
            ->first();
        $this->assertNotNull($monthLedger);
        $this->assertSame(CreditLedgerType::Migration, $monthLedger->type);
        $this->assertSame('Перенос месячных лимитов в кредиты по тарифу', $monthLedger->user_label);

        $extraLedger = CreditLedger::query()
            ->where('user_id', $user->id)
            ->where('idempotency_key', 'migration:user:'.$user->id.':extra')
            ->first();
        $this->assertNotNull($extraLedger);
        $this->assertSame('Перенос дополнительных лимитов в кредиты', $extraLedger->user_label);
    }

    public function test_second_run_does_not_credit_again(): void
    {
        $user = $this->makeSubscriber(
            month: ['ai_text_query' => 10],
            extra: ['ai_image_query' => 2],
        );

        $this->service->run();
        $this->service->run();

        $account = CreditAccount::query()->where('user_id', $user->id)->first();
        $this->assertSame(10, $account->subscription_balance);
        $this->assertSame(10, $account->purchased_balance);
        $this->assertSame(1, CreditLegacyMigration::query()->where('user_id', $user->id)->count());
        $this->assertSame(2, CreditLedger::query()->where('user_id', $user->id)->count());
    }

    public function test_old_extra_run_does_not_duplicate_extra_but_migrates_month(): void
    {
        $user = $this->makeSubscriber(
            month: ['ai_text_query' => 8],
            extra: ['ai_text_query' => 10, 'ai_image_query' => 2],
        );

        app(CreditBillingService::class)->addPurchased($user, 20, [
            'type' => CreditLedgerType::Migration,
            'idempotency_key' => 'migration:user:'.$user->id,
            'user_label' => 'Перенесены купленные лимиты',
        ]);
        CreditLegacyMigration::query()->create([
            'user_id' => $user->id,
            'source_extra_limits' => ['ai_text_query' => 10, 'ai_image_query' => 2],
            'coefficients' => ['ai_text_query' => 1, 'ai_image_query' => 5],
            'purchased_credits' => 20,
            'ran_at' => now(),
        ]);

        $this->service->run();
        $this->service->run();

        $account = CreditAccount::query()->where('user_id', $user->id)->first();
        $this->assertSame(8, $account->subscription_balance);
        $this->assertSame(20, $account->purchased_balance);
        $this->assertSame(1, CreditLegacyMigration::query()->where('user_id', $user->id)->count());
    }

    public function test_does_not_change_source_limit_json(): void
    {
        $month = ['ai_text_query' => 6, 'wb_cabinets' => 2];
        $extra = ['ai_image_query' => 1];
        $user = $this->makeSubscriber(month: $month, extra: $extra);

        $this->service->run();

        $subscription = SubscribersSubscriptions::query()
            ->where('subscribers_id', Subscribers::query()->where('user_id', $user->id)->value('id'))
            ->first();

        $this->assertSame($month, $this->jsonColumn($subscription, 'limits_month'));
        $this->assertSame($extra, $this->jsonColumn($subscription, 'extra_limits_month'));
    }

    public function test_plan_credits_per_period_becomes_converted_package(): void
    {
        $plan = $this->makePlan([
            'ai_text_query' => 10,
            'ai_image_query' => 4,
            'wb_cabinets' => 3,
        ], previousCredits: 50);

        $this->service->run();
        $this->service->run();

        $plan->refresh();
        $this->assertSame(30, (int) $plan->credits_per_period);
        $this->assertSame('Базовый', $plan->name);
        $this->assertSame(1000.0, (float) $plan->price);
        $planLimits = $this->jsonColumn($plan, 'limits_month');
        $this->assertSame(10, $planLimits['ai_text_query']);
        $this->assertSame(4, $planLimits['ai_image_query']);
        $this->assertSame(3, $planLimits['wb_cabinets']);
        $this->assertSame(1, CreditLegacyPlanMigration::query()->where('plan_id', $plan->id)->count());

        $audit = CreditLegacyPlanMigration::query()->where('plan_id', $plan->id)->first();
        $this->assertSame(50, $audit->previous_credits_per_period);
        $this->assertSame(30, $audit->new_credits_per_period);
    }

    public function test_grant_period_after_migration_replaces_subscription_keeps_purchased(): void
    {
        $user = $this->makeSubscriber(
            month: ['ai_text_query' => 10],
            extra: ['ai_image_query' => 2],
            creditsPerPeriod: 40,
        );

        $this->service->migrateUsers();

        $subscription = SubscribersSubscriptions::query()
            ->where('subscribers_id', Subscribers::query()->where('user_id', $user->id)->value('id'))
            ->first();
        $plan = $subscription->getPlan();

        app(CreditBillingService::class)->grantPeriod($user, $subscription, $plan);

        $account = CreditAccount::query()->where('user_id', $user->id)->first();
        $this->assertSame(40, $account->subscription_balance);
        $this->assertSame(10, $account->purchased_balance);
    }

    public function test_zero_and_unlimited_do_not_credit(): void
    {
        $user = $this->makeSubscriber(
            month: ['ai_text_query' => 0, 'ai_image_query' => ToolLimits::UNLIMITED_VALUE],
            extra: [],
        );

        $this->service->run();

        $this->assertSame(0, CreditAccount::query()->where('user_id', $user->id)->count());
        $this->assertSame(0, CreditLedger::query()->where('user_id', $user->id)->count());
        $audit = CreditLegacyMigration::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($audit);
        $this->assertSame(0, $audit->subscription_credits);
        $this->assertSame(0, $audit->purchased_credits);
    }

    public function test_not_ready_throws_without_partial_write(): void
    {
        Schema::drop('credit_services');

        $this->expectException(InvalidCreditOperationException::class);

        app(LegacyCreditMigrationService::class)->run();
    }

    /**
     * @param  array<string, int>  $month
     * @param  array<string, int>  $extra
     */
    private function makeSubscriber(
        array $month,
        array $extra,
        int $creditsPerPeriod = 0,
        string $email = 'migrate@example.com',
    ): User {
        $user = User::query()->create([
            'name' => 'Миграция',
            'email' => $email,
            'password' => Hash::make('password'),
        ]);
        $subscriber = Subscribers::query()->create([
            'user_id' => $user->id,
            'status' => 1,
        ]);
        $plan = $this->makePlan($month, $creditsPerPeriod);
        $subscription = SubscribersSubscriptions::query()->create([
            'subscribers_id' => $subscriber->id,
            'plan_id' => $plan->id,
            'limits_plan' => [],
            'start_date' => Carbon::now(),
            'end_date' => Carbon::now()->addDays(30),
            'status' => 1,
        ]);
        $this->writeJsonColumn($subscription, 'limits_month', $month);
        $this->writeJsonColumn($subscription, 'extra_limits_month', $extra);

        return $user;
    }

    /**
     * @param  array<string, int>  $month
     */
    private function makePlan(array $month, int $previousCredits = 0): SubscribersPlans
    {
        $plan = SubscribersPlans::query()->create([
            'name' => 'Базовый',
            'price' => 1000,
            'duration' => 30,
            'description' => '',
            'limits_plan' => ['wb_cabinets' => $month['wb_cabinets'] ?? 1],
            'credits_per_period' => $previousCredits,
            'permissions' => ['subscriber'],
            'status' => 1,
            'hidden' => 0,
        ]);
        $this->writeJsonColumn($plan, 'limits_month', $month);

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function writeJsonColumn(object $model, string $column, array $value): void
    {
        if (! Schema::hasColumn($model->getTable(), $column)) {
            return;
        }

        \Illuminate\Support\Facades\DB::table($model->getTable())
            ->where('id', $model->id)
            ->update([$column => json_encode($value, JSON_UNESCAPED_UNICODE)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonColumn(object $model, string $column): array
    {
        $raw = \Illuminate\Support\Facades\DB::table($model->getTable())
            ->where('id', $model->id)
            ->value($column);

        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
