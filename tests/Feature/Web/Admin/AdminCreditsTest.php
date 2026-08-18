<?php

namespace Tests\Feature\Web\Admin;

use App\Models\Credits\CreditAccount;
use App\Models\Subscribers\Subscribers;
use App\Models\Subscribers\SubscribersPlans;
use App\Models\User;
use App\Services\Credits\CreditBillingService;
use App\Services\Credits\CreditSpendRequest;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Web\Auth\WebAuthTestCase;
use Tests\Support\CreatesCreditBillingSchema;

class AdminCreditsTest extends WebAuthTestCase
{
    use CreatesCreditBillingSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setupCreditBillingSchema();

        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'subscriber', 'guard_name' => 'web']);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function test_admin_can_save_plan_credits_per_period(): void
    {
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin)
            ->post('/cw-page/plans', [
                'name' => 'AI тариф',
                'price' => 3000,
                'duration' => 30,
                'description' => '',
                'limits_plan' => '',
                'credits_per_period' => 300,
                'permissions' => ['subscriber'],
                'status' => 1,
                'hidden' => 0,
            ])
            ->assertRedirect(route('admin.plans.index'));

        $plan = SubscribersPlans::query()->where('name', 'AI тариф')->first();
        $this->assertNotNull($plan);
        $this->assertSame(300, (int) $plan->credits_per_period);
    }

    public function test_plan_update_keeps_credits_and_cabinet_limits(): void
    {
        $admin = $this->makeSuperAdmin();

        $plan = SubscribersPlans::query()->create([
            'name' => 'Старый AI',
            'price' => 1000,
            'duration' => 30,
            'description' => '',
            'limits_plan' => ['wb_cabinets' => 2],
            'credits_per_period' => 100,
            'permissions' => ['subscriber'],
            'status' => 1,
            'hidden' => 0,
        ]);

        $user = User::factory()->create(['password' => Hash::make('password')]);
        $subscriber = Subscribers::query()->create(['user_id' => $user->id, 'status' => 1]);
        $subscription = \App\Models\Subscribers\SubscribersSubscriptions::query()->create([
            'subscribers_id' => $subscriber->id,
            'plan_id' => $plan->id,
            'status' => 1,
            'limits_plan' => ['wb_cabinets' => 2],
            'end_date' => now()->addDays(10),
        ]);

        $this->actingAs($admin)
            ->put("/cw-page/plans/{$plan->id}", [
                'name' => 'Старый AI',
                'price' => 1000,
                'duration' => 30,
                'description' => '',
                'limits_plan' => 'wb_cabinets:3',
                'credits_per_period' => 100,
                'permissions' => ['subscriber'],
                'status' => 1,
                'hidden' => 0,
            ])
            ->assertRedirect(route('admin.plans.index'));

        $plan->refresh();
        $subscription->refresh();

        $this->assertSame(100, (int) $plan->credits_per_period);
        $this->assertSame(3, (int) ($plan->limits_plan['wb_cabinets'] ?? 0));
        $this->assertSame(2, (int) ($subscription->limits_plan['wb_cabinets'] ?? 0));
    }

    public function test_admin_can_adjust_subscriber_credits(): void
    {
        $admin = $this->makeSuperAdmin();
        $subscriber = $this->makeSubscriber();

        $this->actingAs($admin)
            ->post("/cw-page/subscribers/{$subscriber->id}/credits/adjust", [
                'subscription_delta' => 50,
                'purchased_delta' => 20,
                'reason' => 'Компенсация',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $account = CreditAccount::query()->where('user_id', $subscriber->user_id)->first();
        $this->assertNotNull($account);
        $this->assertSame(50, $account->subscription_balance);
        $this->assertSame(20, $account->purchased_balance);
    }

    public function test_admin_sees_credits_on_subscriber_card(): void
    {
        $admin = $this->makeSuperAdmin();
        $subscriber = $this->makeSubscriber();

        CreditAccount::query()->create([
            'user_id' => $subscriber->user_id,
            'subscription_balance' => 120,
            'purchased_balance' => 80,
        ]);

        $this->actingAs($admin)
            ->get("/cw-page/subscribers/{$subscriber->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Subscribers/Edit')
                ->where('credits.available', 200)
                ->where('credits.subscription', 120)
                ->where('credits.purchased', 80));
    }

    public function test_admin_sees_credit_spend_history_on_subscriber_card(): void
    {
        $admin = $this->makeSuperAdmin();
        $subscriber = $this->makeSubscriber();

        CreditAccount::query()->create([
            'user_id' => $subscriber->user_id,
            'subscription_balance' => 20,
            'purchased_balance' => 0,
        ]);

        app(CreditBillingService::class)->spend($subscriber->user, new CreditSpendRequest(
            amount: 3,
            serviceCode: 'generate_text',
            idempotencyKey: 'admin-hist-spend',
            userLabel: 'Генерация текста',
        ));

        $this->actingAs($admin)
            ->get("/cw-page/subscribers/{$subscriber->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Subscribers/Edit')
                ->has('creditHistory', 1)
                ->where('creditHistory.0.amount', 3)
                ->where('creditHistory.0.direction', 'debit')
                ->where('creditHistory.0.user_label', 'Генерация текста'));
    }

    private function makeSuperAdmin(): User
    {
        $user = User::factory()->create([
            'password' => Hash::make('password'),
        ]);
        $user->assignRole('super-admin');

        return $user;
    }

    private function makeSubscriber(): Subscribers
    {
        $user = User::factory()->create([
            'password' => Hash::make('password'),
        ]);

        return Subscribers::query()->create([
            'user_id' => $user->id,
            'status' => 1,
        ]);
    }
}
