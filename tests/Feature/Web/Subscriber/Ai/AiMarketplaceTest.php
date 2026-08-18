<?php

namespace Tests\Feature\Web\Subscriber\Ai;

use App\Models\Credits\CreditAccount;
use App\Models\Credits\CreditLedger;
use App\Models\Subscribers\Subscribers;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\User;
use App\Services\Gemini\GeminiApiClient;
use Database\Seeders\CreditPricingSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Web\Auth\WebAuthTestCase;
use Tests\Support\CreatesCreditBillingSchema;

class AiMarketplaceTest extends WebAuthTestCase
{
    use CreatesCreditBillingSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setupAiMarketplaceSchema();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::firstOrCreate([
            'name' => 'subscriber ai',
            'guard_name' => 'web',
        ]);
    }

    public function test_guest_cannot_access_text_page(): void
    {
        $this->get('/panel/ai/text')->assertRedirect('/login');
    }

    public function test_user_without_permission_cannot_access_text_page(): void
    {
        $user = $this->createSubscriberUser();

        $this->actingAs($user)
            ->get('/panel/ai/text')
            ->assertForbidden();
    }

    public function test_subscriber_with_permission_can_access_ai_pages(): void
    {
        $user = $this->createSubscriberUser(withAiPermission: true);

        $this->actingAs($user)
            ->get('/panel/ai')
            ->assertRedirect('/panel/ai/text');

        $this->actingAs($user)
            ->get('/panel/ai/text')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Ai/Text')
                ->has('pricing')
                ->has('pricing.text.amount')
                ->has('pricing.image.amounts')
                ->has('pricing.video.amounts'));

        $this->actingAs($user)
            ->get('/panel/ai/image')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Subscriber/Ai/Image'));

        $this->actingAs($user)
            ->get('/panel/ai/image/history')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Subscriber/Ai/ImageHistory'));

        $this->actingAs($user)
            ->get('/panel/ai/video')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Subscriber/Ai/Video'));

        $this->actingAs($user)
            ->get('/panel/ai/video/history')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Subscriber/Ai/VideoHistory'));
    }

    public function test_marketplace_requires_task_type(): void
    {
        $user = $this->createSubscriberUser(withAiPermission: true);

        $this->actingAs($user)
            ->postJson('/panel/ai/marketplace', [])
            ->assertStatus(422);
    }

    public function test_video_status_requires_auth(): void
    {
        $this->getJson('/panel/ai/video/status/test-request-id')
            ->assertUnauthorized();
    }

    public function test_media_endpoint_requires_auth(): void
    {
        $this->get('/panel/ai/media/generated-videos/user-1/test.mp4')
            ->assertRedirect();
    }

    public function test_media_endpoint_rejects_foreign_user_path(): void
    {
        $user = $this->createSubscriberUser(withAiPermission: true);

        $this->actingAs($user)
            ->get('/panel/ai/media/generated-videos/user-999/test.mp4')
            ->assertNotFound();
    }

    public function test_media_endpoint_supports_range_requests(): void
    {
        Storage::fake('private');

        $user = $this->createSubscriberUser(withAiPermission: true);
        $relativePath = 'generated-videos/user-' . $user->id . '/2026/test.mp4';
        $storagePath = 'ai/' . $relativePath;
        Storage::disk('private')->put($storagePath, str_repeat('a', 1000));

        $this->actingAs($user)
            ->withHeader('Range', 'bytes=0-99')
            ->get('/panel/ai/media/' . $relativePath)
            ->assertStatus(206)
            ->assertHeader('Accept-Ranges', 'bytes')
            ->assertHeader('Content-Range', 'bytes 0-99/1000');
    }

    public function test_media_endpoint_serves_source_image(): void
    {
        Storage::fake('private');

        $user = $this->createSubscriberUser(withAiPermission: true);
        $relativePath = 'source-images/user-' . $user->id . '/2026/demo.jpg';
        $storagePath = 'ai/' . $relativePath;
        $binary = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        Storage::disk('private')->put($storagePath, $binary);

        $this->actingAs($user)
            ->get('/panel/ai/media/' . $relativePath)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Content-Disposition', 'inline; filename="demo.jpg"');
    }

    public function test_text_generation_spends_one_credit(): void
    {
        $this->setupCreditsForMarketplace();
        $user = $this->createSubscriberUser(withAiPermission: true);
        $this->grantCredits($user, 10);

        $gemini = Mockery::mock(GeminiApiClient::class);
        $gemini->shouldReceive('generateProText')
            ->once()
            ->andReturn([
                'success' => true,
                'status' => 200,
                'data' => ['text' => 'Готовое описание'],
            ]);
        $gemini->shouldReceive('extractText')
            ->once()
            ->andReturn('Готовое описание');
        $this->app->instance(GeminiApiClient::class, $gemini);

        $this->actingAs($user)
            ->postJson('/panel/ai/marketplace', [
                'task_type' => 'rewrite_text',
                'description' => 'Исходный текст карточки для проверки',
                'credits' => 99,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('credits_charged', 1);

        $this->assertSame(9, CreditAccount::query()->where('user_id', $user->id)->first()?->available());
        $this->assertSame(1, CreditLedger::query()->where('user_id', $user->id)->where('service_code', 'generate_text')->count());
    }

    public function test_text_generation_without_credits_does_not_call_provider(): void
    {
        $this->setupCreditsForMarketplace();
        $user = $this->createSubscriberUser(withAiPermission: true);

        $gemini = Mockery::mock(GeminiApiClient::class);
        $gemini->shouldNotReceive('generateProText');
        $this->app->instance(GeminiApiClient::class, $gemini);

        $response = $this->actingAs($user)
            ->postJson('/panel/ai/marketplace', [
                'task_type' => 'rewrite_text',
                'description' => 'Исходный текст карточки для проверки',
            ])
            ->assertOk()
            ->assertJsonPath('success', false);

        $this->assertStringContainsString('Недостаточно кредитов', (string) $response->json('messages.0'));
    }

    public function test_quote_returns_catalog_amount(): void
    {
        $this->setupCreditsForMarketplace();
        $user = $this->createSubscriberUser(withAiPermission: true);

        $this->actingAs($user)
            ->postJson('/panel/ai/quote', ['kind' => 'text'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.amount', 1)
            ->assertJsonPath('data.service', 'generate_text');

        $this->actingAs($user)
            ->postJson('/panel/ai/quote', [
                'kind' => 'image',
                'resolution' => '1K',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.amount', 10);

        $this->actingAs($user)
            ->postJson('/panel/ai/quote', [
                'kind' => 'video',
                'resolution' => '720p',
                'duration' => 5,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.amount', 40);
    }

    private function createSubscriberUser(bool $withAiPermission = false): User
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
        ]);

        $user->assignRole('Подписчик');

        if ($withAiPermission) {
            $user->givePermissionTo('subscriber ai');
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
            'limits_month' => [
                'ai_text_query' => 10,
                'ai_image_query' => 5,
                'ai_video_query' => 30,
            ],
        ]);

        return $user;
    }

    private function setupCreditsForMarketplace(): void
    {
        $this->setupCreditBillingSchema();
        (new CreditPricingSeeder())->run();
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

    private function setupAiMarketplaceSchema(): void
    {
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
                $table->json('extra_limits_month')->nullable();
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