<?php

namespace Tests\Feature\Web\Admin;

use App\Enums\Credits\CreditServiceCode;
use App\Models\Credits\AiCabinetAnalyzerCreditTariff;
use App\Models\Credits\CreditService;
use App\Models\Credits\CreditSetting;
use App\Models\User;
use Database\Seeders\CreditPricingSeeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Web\Auth\WebAuthTestCase;
use Tests\Support\CreatesCreditBillingSchema;

class AdminCreditPricingTest extends WebAuthTestCase
{
    use CreatesCreditBillingSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setupCreditBillingSchema();
        (new CreditPricingSeeder())->run();

        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function test_opening_page_does_not_seed_empty_catalog(): void
    {
        CreditService::query()->delete();
        CreditSetting::query()->delete();
        AiCabinetAnalyzerCreditTariff::query()->delete();

        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin)
            ->get('/cw-page/credit-pricing')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/CreditPricing/Index')
                ->has('services', 0)
                ->has('cabinet_analyzer_tariffs', 0));

        $this->assertSame(0, CreditService::query()->count());
        $this->assertSame(0, AiCabinetAnalyzerCreditTariff::query()->count());
    }

    public function test_super_admin_can_open_credit_pricing_page(): void
    {
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin)
            ->get('/cw-page/credit-pricing')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/CreditPricing/Index')
                ->where('rubles_per_credit', '2.00')
                ->has('services')
                ->has('cabinet_analyzer_models')
                ->has('cabinet_analyzer_tariffs')
                ->has('cabinet_analyzer_charges'));
    }

    public function test_admin_can_create_and_update_cabinet_analyzer_tariff(): void
    {
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin)
            ->post('/cw-page/credit-pricing/cabinet-analyzer-tariffs', [
                'provider' => 'gemini',
                'model' => 'gemini-test-model',
                'input_credits_per_1k' => 0.05,
                'output_credits_per_1k' => 0.2,
                'coefficient' => 1.5,
                'is_default' => false,
                'is_active' => true,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $tariff = AiCabinetAnalyzerCreditTariff::query()->where('model', 'gemini-test-model')->first();
        $this->assertNotNull($tariff);

        $this->actingAs($admin)
            ->put("/cw-page/credit-pricing/cabinet-analyzer-tariffs/{$tariff->id}", [
                'provider' => 'gemini',
                'model' => 'gemini-test-model',
                'input_credits_per_1k' => 0.08,
                'output_credits_per_1k' => 0.2,
                'coefficient' => 1.5,
                'is_default' => false,
                'is_active' => true,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('0.080000', (string) $tariff->fresh()->input_credits_per_1k);
    }

    public function test_extra_limits_url_redirects_to_credit_pricing(): void
    {
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin)
            ->get('/cw-page/extra-limits')
            ->assertRedirect('/cw-page/credit-pricing');
    }

    public function test_admin_can_update_rubles_per_credit(): void
    {
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin)
            ->put('/cw-page/credit-pricing/rubles', [
                'rubles_per_credit' => 1.5,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(
            '1.50',
            CreditSetting::query()->where('key', CreditSetting::RUBLES_PER_CREDIT)->value('value')
        );
    }

    public function test_admin_can_update_fixed_service_amount(): void
    {
        $admin = $this->makeSuperAdmin();
        $service = CreditService::query()->where('code', CreditServiceCode::GenerateText->value)->firstOrFail();

        $this->actingAs($admin)
            ->put("/cw-page/credit-pricing/services/{$service->id}", [
                'amount' => 3,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(3, (int) $service->fresh()->amount);
    }

    public function test_admin_can_add_image_resolution(): void
    {
        $admin = $this->makeSuperAdmin();
        $service = CreditService::query()->where('code', CreditServiceCode::GenerateImage->value)->firstOrFail();

        $this->actingAs($admin)
            ->post("/cw-page/credit-pricing/services/{$service->id}/tiers", [
                'param_value' => '8K',
                'amount' => 20,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertTrue(
            $service->tiers()->where('param_value', '8K')->where('amount', 20)->exists()
        );
    }

    private function makeSuperAdmin(): User
    {
        $user = User::factory()->create([
            'password' => Hash::make('password'),
        ]);
        $user->assignRole('super-admin');

        return $user;
    }
}
