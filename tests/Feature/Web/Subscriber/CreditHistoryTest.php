<?php

namespace Tests\Feature\Web\Subscriber;

use App\Models\Credits\CreditAccount;
use App\Models\Subscribers\Subscribers;
use App\Models\User;
use App\Services\Credits\CreditBillingService;
use App\Services\Credits\CreditSpendRequest;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Web\Auth\WebAuthTestCase;
use Tests\Support\CreatesCreditBillingSchema;

class CreditHistoryTest extends WebAuthTestCase
{
    use CreatesCreditBillingSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setupCreditBillingSchema();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Permission::firstOrCreate(['name' => 'subscriber', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'panel.access', 'guard_name' => 'web']);
    }

    public function test_subscriber_can_open_credit_history(): void
    {
        $user = $this->makeSubscriberUser();

        CreditAccount::query()->create([
            'user_id' => $user->id,
            'subscription_balance' => 10,
            'purchased_balance' => 5,
        ]);

        app(CreditBillingService::class)->spend($user, new CreditSpendRequest(
            amount: 3,
            serviceCode: 'generate_text',
            idempotencyKey: 'hist-1',
            userLabel: 'Списано 3 кредита',
        ));

        $this->actingAs($user)
            ->get('/panel/credits/history')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Credits/History')
                ->where('credits.available', 12)
                ->has('entries', 1));
    }

    public function test_open_hold_is_shown_as_reserved(): void
    {
        $user = $this->makeSubscriberUser();

        CreditAccount::query()->create([
            'user_id' => $user->id,
            'subscription_balance' => 20,
            'purchased_balance' => 0,
        ]);

        app(CreditBillingService::class)->reserve($user, new CreditSpendRequest(
            amount: 10,
            serviceCode: 'oz_ai_cabinet_analyzer',
            idempotencyKey: 'hist-open-hold',
            userLabel: 'ИИ-анализ кабинета Ozon: Продажи и ассортимент',
        ));

        $this->actingAs($user)
            ->get('/panel/credits/history')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Credits/History')
                ->has('entries', 1)
                ->where('entries.0.type', 'hold')
                ->where('entries.0.amount', 10)
                ->where('entries.0.user_label', 'Зарезервировано: ИИ-анализ кабинета Ozon: Продажи и ассортимент')
                ->where('credits.available', 10)
                ->where('credits.held', 10));
    }

    public function test_history_hides_hold_and_shows_one_capture(): void
    {
        $user = $this->makeSubscriberUser();

        CreditAccount::query()->create([
            'user_id' => $user->id,
            'subscription_balance' => 10,
            'purchased_balance' => 0,
        ]);

        $billing = app(CreditBillingService::class);
        $hold = $billing->reserve($user, new CreditSpendRequest(
            amount: 1,
            serviceCode: 'feedback_answer',
            idempotencyKey: 'hist-hold-1',
            operationParams: ['user_label' => 'Ответ на отзыв Wildberries'],
            userLabel: 'Ответ на отзыв Wildberries',
        ));
        $billing->capture($hold);

        $this->actingAs($user)
            ->get('/panel/credits/history')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Credits/History')
                ->has('entries', 1)
                ->where('entries.0.amount', 1)
                ->where('entries.0.type', 'capture')
                ->where('entries.0.user_label', 'Ответ на отзыв Wildberries'));
    }

    public function test_panel_shared_props_include_credits(): void
    {
        $user = $this->makeSubscriberUser();

        CreditAccount::query()->create([
            'user_id' => $user->id,
            'subscription_balance' => 7,
            'purchased_balance' => 3,
        ]);

        $this->actingAs($user)
            ->get('/panel/credits/history')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('subscriber.credits.available', 10)
                ->where('subscriber.credits.subscription', 7)
                ->where('subscriber.credits.purchased', 3)
                ->where('subscriber.rubles_per_credit', '2'));
    }

    private function makeSubscriberUser(): User
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
        ]);
        $user->assignRole('Подписчик');
        $user->givePermissionTo('subscriber');

        Subscribers::query()->create([
            'user_id' => $user->id,
            'status' => 1,
        ]);

        return $user;
    }
}
