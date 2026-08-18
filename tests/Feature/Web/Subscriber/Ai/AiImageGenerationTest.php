<?php

namespace Tests\Feature\Web\Subscriber\Ai;

use App\Models\AiImageGeneration;
use App\Models\AiImageGenerationTask;
use App\Models\Credits\CreditAccount;
use App\Models\Credits\CreditLedger;
use App\Models\Subscribers\Subscribers;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\User;
use App\Services\Ai\AiImageGenerationService;
use App\Services\Ai\AiImageService;
use App\Services\Ai\AiMediaStorageService;
use App\Services\Gemini\GeminiApiClient;
use App\Services\Grok\GrokImageApiClient;
use Database\Seeders\CreditPricingSeeder;
use Tests\Support\CreatesCreditBillingSchema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use ReflectionMethod;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Web\Auth\WebAuthTestCase;

class AiImageGenerationTest extends WebAuthTestCase
{
    use CreatesCreditBillingSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setupAiImageGenerationSchema();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::firstOrCreate([
            'name' => 'subscriber ai',
            'guard_name' => 'web',
        ]);
    }

    public function test_guest_cannot_access_image_history_page(): void
    {
        $this->get('/panel/ai/image/history')->assertRedirect('/login');
    }

    public function test_subscriber_can_access_image_history_page(): void
    {
        $user = $this->createSubscriberUser(withAiPermission: true);

        $this->actingAs($user)
            ->get('/panel/ai/image/history')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Subscriber/Ai/ImageHistory'));
    }

    public function test_subscriber_can_access_image_generation_page_by_uuid(): void
    {
        $user = $this->createSubscriberUser(withAiPermission: true);

        $generation = AiImageGeneration::query()->create([
            'subscriber_id' => (int) $user->subscriber->id,
            'user_id' => $user->id,
            'title' => 'UUID page',
        ]);

        $this->actingAs($user)
            ->get('/panel/ai/image/' . $generation->uuid)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Ai/Image')
                ->where('generationUuid', $generation->uuid));
    }

    public function test_guest_cannot_list_generations(): void
    {
        $this->getJson('/panel/ai/image/generations')->assertUnauthorized();
    }

    public function test_guest_cannot_start_image_generation(): void
    {
        $this->postJson('/panel/ai/image/start', [
            'task_type' => 'generate_image',
            'image_prompt' => 'Test prompt',
        ])->assertUnauthorized();
    }

    public function test_subscriber_can_list_own_generations(): void
    {
        $user = $this->createSubscriberUser(withAiPermission: true);
        $subscriberId = (int) $user->subscriber->id;

        $generation = AiImageGeneration::query()->create([
            'subscriber_id' => $subscriberId,
            'user_id' => $user->id,
            'title' => 'Тестовая генерация',
        ]);

        AiImageGenerationTask::query()->create([
            'image_generation_id' => $generation->id,
            'subscriber_id' => $subscriberId,
            'user_id' => $user->id,
            'task_type' => 'generate_image',
            'prompt' => 'Тестовый prompt',
            'image_variants' => 1,
            'resolution' => 'default',
            'status' => AiImageGenerationTask::STATUS_DONE,
            'result_images' => [[
                'path' => 'ai/source-images/user-' . $user->id . '/2026/demo.png',
                'url' => '/panel/ai/media/source-images/user-' . $user->id . '/2026/demo.png',
            ]],
        ]);

        $otherUser = $this->createSubscriberUser(withAiPermission: true);
        AiImageGeneration::query()->create([
            'subscriber_id' => (int) $otherUser->subscriber->id,
            'user_id' => $otherUser->id,
            'title' => 'Чужая генерация',
        ]);

        $this->actingAs($user)
            ->getJson('/panel/ai/image/generations')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $generation->id)
            ->assertJsonPath('data.0.uuid', $generation->uuid)
            ->assertJsonPath('data.0.title', 'Тестовая генерация')
            ->assertJsonPath('data.0.tasks_count', 1);
    }

    public function test_open_done_generation_returns_source_images_before_results(): void
    {
        $user = $this->createSubscriberUser(withAiPermission: true);
        $subscriberId = (int) $user->subscriber->id;
        $sourcePath = 'ai/source-images/user-' . $user->id . '/2026/source.jpg';
        $resultPath = 'ai/source-images/user-' . $user->id . '/2026/result.png';

        $generation = AiImageGeneration::query()->create([
            'subscriber_id' => $subscriberId,
            'user_id' => $user->id,
            'title' => 'С исходником',
        ]);

        AiImageGenerationTask::query()->create([
            'image_generation_id' => $generation->id,
            'subscriber_id' => $subscriberId,
            'user_id' => $user->id,
            'task_type' => 'generate_image',
            'prompt' => 'Prompt',
            'image_variants' => 1,
            'resolution' => 'default',
            'status' => AiImageGenerationTask::STATUS_DONE,
            'source_images' => [[
                'path' => $sourcePath,
                'url_preview' => '/panel/ai/media/' . $sourcePath,
            ]],
            'result_images' => [[
                'path' => $resultPath,
                'url' => '/panel/ai/media/' . $resultPath,
            ]],
        ]);

        $response = $this->actingAs($user)
            ->getJson('/panel/ai/image/generations/' . $generation->uuid)
            ->assertOk();

        $sourceImageUrl = (string) $response->json('data.tasks.0.source_images.0');
        $resultImageUrl = (string) $response->json('data.tasks.0.images.0');

        $this->assertSame(
            '/panel/ai/media/source-images/user-' . $user->id . '/2026/source.jpg',
            $sourceImageUrl,
        );
        $this->assertSame(
            '/panel/ai/media/source-images/user-' . $user->id . '/2026/result.png',
            $resultImageUrl,
        );
    }

    public function test_open_done_generation_returns_resolved_image_url(): void
    {
        $user = $this->createSubscriberUser(withAiPermission: true);
        $subscriberId = (int) $user->subscriber->id;
        $imagePath = 'ai/source-images/user-' . $user->id . '/2026/demo.png';

        $generation = AiImageGeneration::query()->create([
            'subscriber_id' => $subscriberId,
            'user_id' => $user->id,
            'title' => 'Готовое изображение',
        ]);

        AiImageGenerationTask::query()->create([
            'image_generation_id' => $generation->id,
            'subscriber_id' => $subscriberId,
            'user_id' => $user->id,
            'task_type' => 'generate_image',
            'prompt' => 'Prompt',
            'image_variants' => 1,
            'resolution' => 'default',
            'status' => AiImageGenerationTask::STATUS_DONE,
            'result_images' => [[
                'path' => $imagePath,
                'signed_url' => '/panel/ai/media/' . rawurlencode($imagePath),
            ]],
        ]);

        $response = $this->actingAs($user)
            ->getJson('/panel/ai/image/generations/' . $generation->uuid)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.uuid', $generation->uuid)
            ->assertJsonPath('data.tasks.0.status', 'done');

        $imageUrl = (string) $response->json('data.tasks.0.images.0');
        $this->assertSame(
            '/panel/ai/media/source-images/user-' . $user->id . '/2026/demo.png',
            $imageUrl,
        );
    }

    public function test_delete_generation_removes_tasks_and_media_files(): void
    {
        Storage::fake('private');

        $user = $this->createSubscriberUser(withAiPermission: true);
        $subscriberId = (int) $user->subscriber->id;

        $resultPath = 'ai/source-images/user-' . $user->id . '/2026/result.png';
        $sourcePath = 'ai/source-images/user-' . $user->id . '/2026/source.jpg';
        Storage::disk('private')->put($resultPath, 'result-binary');
        Storage::disk('private')->put($sourcePath, 'source-binary');

        $generation = AiImageGeneration::query()->create([
            'subscriber_id' => $subscriberId,
            'user_id' => $user->id,
            'title' => 'Удалить меня',
        ]);

        AiImageGenerationTask::query()->create([
            'image_generation_id' => $generation->id,
            'subscriber_id' => $subscriberId,
            'user_id' => $user->id,
            'task_type' => 'generate_image',
            'prompt' => 'Prompt',
            'image_variants' => 1,
            'resolution' => 'default',
            'status' => AiImageGenerationTask::STATUS_DONE,
            'source_images' => [[
                'path' => $sourcePath,
                'url_preview' => '/panel/ai/media/' . $sourcePath,
            ]],
            'result_images' => [[
                'path' => $resultPath,
                'url' => '/panel/ai/media/' . $resultPath,
            ]],
        ]);

        $this->actingAs($user)
            ->deleteJson('/panel/ai/image/generations/' . $generation->uuid)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('ai_image_generations', ['id' => $generation->id]);
        $this->assertDatabaseMissing('ai_image_generation_tasks', ['image_generation_id' => $generation->id]);
        Storage::disk('private')->assertMissing($resultPath);
        Storage::disk('private')->assertMissing($sourcePath);
    }

    public function test_subscriber_cannot_delete_foreign_generation(): void
    {
        $owner = $this->createSubscriberUser(withAiPermission: true);
        $intruder = $this->createSubscriberUser(withAiPermission: true);

        $generation = AiImageGeneration::query()->create([
            'subscriber_id' => (int) $owner->subscriber->id,
            'user_id' => $owner->id,
            'title' => 'Чужая',
        ]);

        $this->actingAs($intruder)
            ->deleteJson('/panel/ai/image/generations/' . $generation->uuid)
            ->assertNotFound();
    }

    public function test_image_start_validates_required_fields(): void
    {
        $user = $this->createSubscriberUser(withAiPermission: true);

        $this->actingAs($user)
            ->postJson('/panel/ai/image/start', [
                'task_type' => 'generate_image',
            ])
            ->assertOk()
            ->assertJsonPath('success', false);
    }

    public function test_store_image_accepts_panel_media_url(): void
    {
        Storage::fake('private');

        $user = $this->createSubscriberUser(withAiPermission: true);
        $existingPath = 'ai/source-images/user-' . $user->id . '/2026/existing.png';
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        Storage::disk('private')->put($existingPath, $png);

        $panelUrl = '/panel/ai/media/source-images/user-' . $user->id . '/2026/existing.png';
        $stored = app(AiMediaStorageService::class)->storeImageAndGetSignedUrl($panelUrl, $user->id);

        $this->assertNotSame('', (string) ($stored['path'] ?? ''));
        $this->assertNotSame($existingPath, (string) ($stored['path'] ?? ''));
        Storage::disk('private')->assertExists((string) $stored['path']);
    }

    public function test_resolve_image_inline_data_accepts_panel_media_url(): void
    {
        Storage::fake('private');

        $user = $this->createSubscriberUser(withAiPermission: true);
        $existingPath = 'ai/source-images/user-' . $user->id . '/2026/reference.png';
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        Storage::disk('private')->put($existingPath, $png);

        $panelUrl = '/panel/ai/media/source-images/user-' . $user->id . '/2026/reference.png';
        [$mimeType, $base64] = app(AiMediaStorageService::class)->resolveImageInlineData($panelUrl);

        $this->assertSame('image/png', $mimeType);
        $this->assertSame(base64_encode($png), $base64);
    }

    public function test_is_stored_media_reference_detects_panel_media_url(): void
    {
        $service = app(AiMediaStorageService::class);

        $this->assertTrue($service->isStoredMediaReference('/panel/ai/media/source-images/user-1/2026/demo.png'));
        $this->assertTrue($service->isStoredMediaReference('https://example.com/panel/ai/media/source-images/user-1/2026/demo.png'));
        $this->assertFalse($service->isStoredMediaReference('data:image/png;base64,iVBORw0KGgo='));
    }

    public function test_grok_fallback_resolves_panel_media_paths_to_data_uris(): void
    {
        Storage::fake('private');

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $this->assertNotFalse($png);

        $existingPath = 'ai/source-images/user-1/2026/history-ref.png';
        Storage::disk('private')->put($existingPath, $png);

        $panelUrl = '/panel/ai/media/source-images/user-1/2026/history-ref.png';
        $capturedImages = null;

        $grok = Mockery::mock(GrokImageApiClient::class);
        $grok->shouldReceive('generateOrEditImage')
            ->once()
            ->withArgs(function (string $prompt, array $images, array $options) use (&$capturedImages): bool {
                $capturedImages = $images;

                return $prompt === 'make it brighter' && $options === [];
            })
            ->andReturn([
                'success' => true,
                'status' => 200,
                'messages' => [],
                'data' => [
                    'data' => [[
                        'b64_json' => base64_encode($png),
                    ]],
                ],
            ]);
        $grok->shouldReceive('extractImages')
            ->once()
            ->andReturn([[
                'mime_type' => 'image/png',
                'base64' => base64_encode($png),
                'data_uri' => 'data:image/png;base64,' . base64_encode($png),
            ]]);

        $this->app->instance(GrokImageApiClient::class, $grok);

        $service = app(AiImageService::class);
        $request = Request::create('/panel/ai/image/start', 'POST', [
            'task_type' => 'generate_image',
            'image_prompt' => 'make it brighter',
            'images' => [$panelUrl],
        ]);

        $method = new ReflectionMethod(AiImageService::class, 'generateImagesByGrokFallback');
        $method->setAccessible(true);
        $result = $method->invoke(
            $service,
            $request,
            'generate_image',
            'make it brighter',
            null,
            1,
        );

        $this->assertIsArray($capturedImages);
        $this->assertCount(1, $capturedImages);
        $this->assertStringStartsWith('data:image/png;base64,', (string) $capturedImages[0]);
        $this->assertSame(
            'data:image/png;base64,' . base64_encode($png),
            (string) $capturedImages[0],
        );
        $this->assertCount(1, $result);
    }

    public function test_create_task_recreates_generation_when_session_was_deleted_mid_request(): void
    {
        $user = $this->createSubscriberUser(withAiPermission: true);
        $subscriberId = (int) $user->subscriber->id;
        $service = app(AiImageGenerationService::class);

        $generation = AiImageGeneration::query()->create([
            'subscriber_id' => $subscriberId,
            'user_id' => $user->id,
            'title' => 'Удалённая сессия',
        ]);
        $originalId = (int) $generation->id;
        $originalUuid = $generation->uuid;

        // Simulate: user deleted the session while the AI request was still running.
        // In-memory model still holds the old id (same as production after resolveForStart).
        $generation->delete();
        $this->assertDatabaseMissing('ai_image_generations', ['id' => $originalId]);

        $task = $service->createTask(
            generation: $generation,
            subscriberId: $subscriberId,
            userId: (int) $user->id,
            taskType: 'generate_image',
            prompt: 'сделай фотографию более реалистичной',
            imageVariants: 1,
            resolution: 'default',
            aspectRatio: null,
            sourceImages: null,
            status: AiImageGenerationTask::STATUS_DONE,
            resultImages: [[
                'path' => 'ai/source-images/user-' . $user->id . '/2026/result.jpg',
            ]],
            model: 'grok-imagine-image-quality',
        );

        $this->assertNotNull($task->id);
        $this->assertNotSame($originalId, (int) $task->image_generation_id);
        $this->assertDatabaseHas('ai_image_generation_tasks', [
            'id' => $task->id,
            'image_generation_id' => $task->image_generation_id,
            'status' => AiImageGenerationTask::STATUS_DONE,
        ]);

        $recreated = AiImageGeneration::query()->find($task->image_generation_id);
        $this->assertNotNull($recreated);
        $this->assertSame($originalUuid, $recreated->uuid);
        $this->assertSame($subscriberId, (int) $recreated->subscriber_id);
    }

    public function test_has_stored_source_images_returns_true_only_when_generation_has_sources(): void
    {
        $user = $this->createSubscriberUser(withAiPermission: true);
        $subscriberId = (int) $user->subscriber->id;
        $service = app(AiImageGenerationService::class);

        $emptyGeneration = AiImageGeneration::query()->create([
            'subscriber_id' => $subscriberId,
            'user_id' => $user->id,
            'title' => 'Пустая',
        ]);

        $this->assertFalse($service->hasStoredSourceImages($emptyGeneration));

        AiImageGenerationTask::query()->create([
            'image_generation_id' => $emptyGeneration->id,
            'subscriber_id' => $subscriberId,
            'user_id' => $user->id,
            'task_type' => 'generate_image',
            'prompt' => 'Prompt',
            'image_variants' => 1,
            'resolution' => 'default',
            'status' => AiImageGenerationTask::STATUS_DONE,
            'result_images' => [[
                'path' => 'ai/source-images/user-' . $user->id . '/2026/result.png',
            ]],
        ]);

        $generationWithResultOnly = $emptyGeneration->fresh();
        $generationWithResultOnly->load('tasks');
        $this->assertFalse($service->hasStoredSourceImages($generationWithResultOnly));

        $withSourceGeneration = AiImageGeneration::query()->create([
            'subscriber_id' => $subscriberId,
            'user_id' => $user->id,
            'title' => 'С исходником',
        ]);

        AiImageGenerationTask::query()->create([
            'image_generation_id' => $withSourceGeneration->id,
            'subscriber_id' => $subscriberId,
            'user_id' => $user->id,
            'task_type' => 'generate_image',
            'prompt' => 'Prompt',
            'image_variants' => 1,
            'resolution' => 'default',
            'status' => AiImageGenerationTask::STATUS_DONE,
            'source_images' => [[
                'path' => 'ai/source-images/user-' . $user->id . '/2026/source.png',
            ]],
        ]);

        $this->assertTrue($service->hasStoredSourceImages($withSourceGeneration->fresh()));
    }

    public function test_image_generation_spends_catalog_credits_for_resolution(): void
    {
        Storage::fake('private');
        $this->setupCreditBillingSchema();
        (new CreditPricingSeeder())->run();

        $user = $this->createSubscriberUser(withAiPermission: true);
        $this->grantCredits($user, 20);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

        $gemini = Mockery::mock(GeminiApiClient::class);
        $gemini->shouldReceive('buildImageOptions')->andReturn([]);
        $gemini->shouldReceive('generateImage')->once()->andReturn([
            'success' => true,
            'status' => 200,
            'data' => [],
        ]);
        $gemini->shouldReceive('extractImages')->andReturn([[
            'mime_type' => 'image/png',
            'base64' => base64_encode($png),
        ]]);
        $this->app->instance(GeminiApiClient::class, $gemini);

        $this->actingAs($user)
            ->postJson('/panel/ai/image/start', [
                'task_type' => 'generate_image',
                'image_prompt' => 'Сделай карточку товара',
                'resolution' => '1K',
                'credits' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('credits_charged', 10);

        $this->assertSame(10, CreditAccount::query()->where('user_id', $user->id)->first()?->available());
        $this->assertSame(1, CreditLedger::query()->where('user_id', $user->id)->where('service_code', 'generate_image')->count());
    }

    public function test_image_generation_without_credits_does_not_call_provider(): void
    {
        $this->setupCreditBillingSchema();
        (new CreditPricingSeeder())->run();
        $user = $this->createSubscriberUser(withAiPermission: true);

        $gemini = Mockery::mock(GeminiApiClient::class);
        $gemini->shouldNotReceive('generateImage');
        $this->app->instance(GeminiApiClient::class, $gemini);

        $response = $this->actingAs($user)
            ->postJson('/panel/ai/image/start', [
                'task_type' => 'generate_image',
                'image_prompt' => 'Сделай карточку товара',
                'resolution' => 'default',
            ])
            ->assertOk()
            ->assertJsonPath('success', false);

        $this->assertStringContainsString('Недостаточно кредитов', (string) $response->json('messages.0'));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
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

    private function setupAiImageGenerationSchema(): void
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

        if (! Schema::hasTable('ai_image_generations')) {
            Schema::create('ai_image_generations', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('subscriber_id')->index();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('title', 120)->nullable();
                $table->timestamps();
            });
        } elseif (! Schema::hasColumn('ai_image_generations', 'uuid')) {
            Schema::table('ai_image_generations', function (Blueprint $table) {
                $table->uuid('uuid')->nullable()->unique()->after('id');
            });

            AiImageGeneration::query()
                ->whereNull('uuid')
                ->orderBy('id')
                ->each(function (AiImageGeneration $generation): void {
                    $generation->forceFill(['uuid' => (string) Str::uuid()])->save();
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

        if (! Schema::hasTable('ai_image_generation_tasks')) {
            Schema::create('ai_image_generation_tasks', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('image_generation_id')->index();
                $table->unsignedBigInteger('subscriber_id')->index();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('task_type', 64);
                $table->text('prompt');
                $table->unsignedTinyInteger('image_variants')->default(1);
                $table->string('resolution', 16)->default('default');
                $table->string('aspect_ratio', 16)->nullable();
                $table->json('source_images')->nullable();
                $table->string('status', 32)->default('done');
                $table->json('result_images')->nullable();
                $table->text('error_message')->nullable();
                $table->string('model', 128)->nullable();
                $table->timestamp('limit_consumed_at')->nullable();
                $table->timestamps();
            });
        }
    }
}