<?php

namespace Tests\Unit\Credits;

use App\Enums\Credits\CreditBillingMode;
use App\Enums\Credits\CreditLedgerType;
use App\Enums\Credits\CreditServiceCode;
use App\Models\Credits\CreditAccount;
use App\Models\Credits\CreditLedger;
use App\Models\Credits\CreditService;
use App\Models\User;
use App\Services\Subscriber\Concerns\ChargesFeedbackAnswerCredits;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tests\Support\CreatesCreditBillingSchema;

class ChargesFeedbackAnswerCreditsTest extends TestCase
{
    use CreatesCreditBillingSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setupCreditBillingSchema();

        CreditService::query()->updateOrCreate(
            ['code' => CreditServiceCode::FeedbackAnswer->value],
            [
                'name' => CreditServiceCode::FeedbackAnswer->label(),
                'billing_mode' => CreditBillingMode::Fixed,
                'amount' => 1,
                'sort_order' => 20,
                'is_active' => true,
            ],
        );
    }

    public function test_quote_reads_catalog_amount(): void
    {
        CreditService::query()
            ->where('code', CreditServiceCode::FeedbackAnswer->value)
            ->update(['amount' => 3]);

        $this->assertSame(3, (new FeedbackAnswerCreditsHarness)->publicCost());
    }

    public function test_auto_key_is_idempotent_for_same_review(): void
    {
        $user = $this->makeUser('auto-idempotent@example.com');
        $this->grantCredits($user, 5);
        $harness = new FeedbackAnswerCreditsHarness;
        $key = $harness->publicAutoKey(12, 'rev-9');

        $harness->publicSpend($user, ['mode' => 'auto', 'cabinet_id' => 12, 'review_id' => 'rev-9'], $key);
        $harness->publicSpend($user, ['mode' => 'auto', 'cabinet_id' => 12, 'review_id' => 'rev-9'], $key);

        $account = CreditAccount::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(4, $account->available());
        $this->assertSame(1, CreditLedger::query()->where('user_id', $user->id)->where('type', CreditLedgerType::Spend)->count());
    }

    public function test_failed_generation_does_not_charge(): void
    {
        $user = $this->makeUser('auto-release@example.com');
        $this->grantCredits($user, 5);

        $account = CreditAccount::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(5, $account->available());
        $this->assertSame(0, CreditLedger::query()->where('user_id', $user->id)->where('type', CreditLedgerType::Spend)->count());
    }

    public function test_separate_reviews_are_separate_operations(): void
    {
        $user = $this->makeUser('auto-separate@example.com');
        $this->grantCredits($user, 5);
        $harness = new FeedbackAnswerCreditsHarness;

        $harness->publicSpend($user, ['mode' => 'auto', 'review_id' => 'rev-1'], $harness->publicAutoKey(12, 'rev-1'));
        $harness->publicSpend($user, ['mode' => 'auto', 'review_id' => 'rev-2'], $harness->publicAutoKey(12, 'rev-2'));

        $account = CreditAccount::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(3, $account->available());
        $this->assertSame(2, CreditLedger::query()->where('user_id', $user->id)->where('type', CreditLedgerType::Spend)->count());
    }

    private function makeUser(string $email): User
    {
        return User::query()->create([
            'name' => 'Тест',
            'email' => $email,
            'password' => Hash::make('password'),
        ]);
    }

    private function grantCredits(User $user, int $amount): void
    {
        CreditAccount::query()->create([
            'user_id' => $user->id,
            'subscription_balance' => 0,
            'purchased_balance' => $amount,
            'subscription_held' => 0,
            'purchased_held' => 0,
        ]);
    }
}

class FeedbackAnswerCreditsHarness
{
    use ChargesFeedbackAnswerCredits;

    public function publicCost(): int
    {
        return $this->feedbackAnswerCreditsCost();
    }

    public function publicAutoKey(int $cabinetId, string $reviewId): string
    {
        return $this->autoFeedbackAnswerKey($cabinetId, $reviewId);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function publicSpend(User $user, array $params, string $key): void
    {
        $this->spendFeedbackAnswerCredits($user, $params, $key);
    }
}
