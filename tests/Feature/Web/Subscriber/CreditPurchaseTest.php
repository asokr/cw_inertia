<?php

namespace Tests\Feature\Web\Subscriber;

use App\Models\Credits\CreditAccount;
use App\Models\Credits\CreditSetting;
use App\Models\Subscribers\Subscribers;
use App\Models\Subscribers\SubscribersPlans;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\User;
use Database\Seeders\CreditPricingSeeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Web\Auth\WebAuthTestCase;
use Tests\Support\CreatesCreditBillingSchema;

class CreditPurchaseTest extends WebAuthTestCase
{
    use CreatesCreditBillingSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setupCreditBillingSchema();
        (new CreditPricingSeeder())->run();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::firstOrCreate(['name' => 'subscriber', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

        SubscribersPlans::query()->firstOrCreate(
            ['id' => 1],
            [
                'name' => 'Базовый',
                'description' => 'Test plan',
                'price' => 1000,
                'duration' => 30,
                'limits_plan' => [],
                'limits_month' => [],
                'permissions' => ['subscriber'],
                'status' => 1,
                'hidden' => 0,
            ]
        );
    }

    public function test_purchase_uses_rubles_per_credit_setting(): void
    {
        $user = $this->createSubscriberWithSubscription();
        deposit(500, 'RUB')->to($user)->overcharge()->commit();

        $this->actingAs($user)
            ->post('/panel/credits/purchase', [
                'quantity' => 100,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $account = CreditAccount::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($account);
        $this->assertSame(100, $account->purchased_balance);
        $this->assertEqualsWithDelta(300.0, (float) (string) $user->balance('RUB')->value, 0.01);
    }

    public function test_purchase_uses_updated_rubles_price(): void
    {
        $user = $this->createSubscriberWithSubscription();
        deposit(500, 'RUB')->to($user)->overcharge()->commit();

        CreditSetting::query()
            ->where('key', CreditSetting::RUBLES_PER_CREDIT)
            ->update(['value' => '1.50']);

        $this->actingAs($user)
            ->post('/panel/credits/purchase', [
                'quantity' => 100,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertEqualsWithDelta(350.0, (float) (string) $user->balance('RUB')->value, 0.01);
    }

    public function test_purchase_fails_without_enough_funds(): void
    {
        $user = $this->createSubscriberWithSubscription();

        $this->actingAs($user)
            ->post('/panel/credits/purchase', [
                'quantity' => 50,
            ])
            ->assertRedirect()
            ->assertSessionHas('error', 'Недостаточно средств');

        $this->assertFalse(CreditAccount::query()->where('user_id', $user->id)->exists());
    }

    public function test_purchase_requires_valid_quantity(): void
    {
        $user = $this->createSubscriberWithSubscription();
        deposit(1000, 'RUB')->to($user)->overcharge()->commit();

        $this->actingAs($user)
            ->from('/panel/user/profile')
            ->post('/panel/credits/purchase', [
                'quantity' => 0,
            ])
            ->assertRedirect('/panel/user/profile')
            ->assertSessionHasErrors('quantity');
    }

    public function test_purchase_fails_without_subscription(): void
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

        deposit(1000, 'RUB')->to($user)->overcharge()->commit();

        $this->actingAs($user)
            ->post('/panel/credits/purchase', [
                'quantity' => 10,
            ])
            ->assertRedirect()
            ->assertSessionHas('error', 'У вас нет активной подписки');
    }

    private function createSubscriberWithSubscription(): User
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
        ]);

        $user->assignRole('Подписчик');
        $user->givePermissionTo('subscriber');

        $subscriber = Subscribers::query()->create([
            'user_id' => $user->id,
            'status' => 1,
        ]);

        SubscribersSubscriptions::query()->create([
            'subscribers_id' => $subscriber->id,
            'plan_id' => 1,
            'status' => 1,
            'limits_plan' => [],
            'limits_month' => [],
            'extra_limits_month' => [],
            'end_date' => now()->addDays(30),
        ]);

        $user->load('subscriber');

        return $user;
    }
}
