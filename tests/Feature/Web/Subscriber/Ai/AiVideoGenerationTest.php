<?php

namespace Tests\Feature\Web\Subscriber\Ai;

use App\Models\AiVideoGeneration;
use App\Models\AiVideoGenerationTask;
use App\Models\Credits\CreditAccount;
use App\Models\Credits\CreditHold;
use App\Models\Credits\CreditLedger;
use App\Models\Subscribers\Subscribers;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\User;
use App\Services\Ai\AiMediaStorageService;
use App\Services\Grok\GrokVideoApiClient;
use Database\Seeders\CreditPricingSeeder;
use Mockery;
use Tests\Support\CreatesCreditBillingSchema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\Support\Mp4BoxBuilder;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Web\Auth\WebAuthTestCase;

class AiVideoGenerationTest extends WebAuthTestCase
{
    use CreatesCreditBillingSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setupAiVideoGenerationSchema();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::firstOrCreate([
            'name' => 'subscriber ai',
            'guard_name' => 'web',
        ]);
    }

    public function test_guest_cannot_access_video_history_page(): void
    {
        $this->get('/panel/ai/video/history')->assertRedirect('/login');
    }

    public function test_subscriber_can_access_video_history_page(): void
    {
        $user = $this->createSubscriberUser(withAiPermission: true);

        $this->actingAs($user)
            ->get('/panel/ai/video/history')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Subscriber/Ai/VideoHistory'));
    }

    public function test_subscriber_can_access_video_generation_page_by_uuid(): void
    {
        $user = $this->createSubscriberUser(withAiPermission: true);

        $generation = AiVideoGeneration::query()->create([
            'subscriber_id' => (int) $user->subscriber->id,
            'user_id' => $user->id,
            'title' => 'UUID page',
        ]);

        $this->actingAs($user)
            ->get('/panel/ai/video/' . $generation->uuid)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Ai/Video')
                ->where('generationUuid', $generation->uuid));
    }

    public function test_guest_cannot_list_generations(): void
    {
        $this->getJson('/panel/ai/video/generations')->assertUnauthorized();
    }

    public function test_subscriber_can_list_own_generations(): void
    {
        $user = $this->createSubscriberUser(withAiPermission: true);
        $subscriberId = (int) $user->subscriber->id;

        $generation = AiVideoGeneration::query()->create([
            'subscriber_id' => $subscriberId,
            'user_id' => $user->id,
            'title' => 'Тестовая генерация',
        ]);

        AiVideoGenerationTask::query()->create([
            'video_generation_id' => $generation->id,
            'subscriber_id' => $subscriberId,
            'user_id' => $user->id,
            'external_request_id' => 'req-1',
            'task_type' => 'generate_video',
            'prompt' => 'Тестовый prompt',
            'duration' => 5,
            'resolution' => '480p',
            'status' => AiVideoGenerationTask::STATUS_DONE,
            'result_video' => [
                'path' => 'ai/generated-videos/user-' . $user->id . '/2026/demo.mp4',
                'url' => '/panel/ai/media/generated-videos/user-' . $user->id . '/2026/demo.mp4',
            ],
        ]);

        $otherUser = $this->createSubscriberUser(withAiPermission: true);
        AiVideoGeneration::query()->create([
            'subscriber_id' => (int) $otherUser->subscriber->id,
            'user_id' => $otherUser->id,
            'title' => 'Чужая генерация',
        ]);

        $this->actingAs($user)
            ->getJson('/panel/ai/video/generations')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $generation->id)
            ->assertJsonPath('data.0.uuid', $generation->uuid)
            ->assertJsonPath('data.0.title', 'Тестовая генерация')
            ->assertJsonPath('data.0.tasks_count', 1);
    }

    public function test_open_done_generation_returns_resolved_video_url(): void
    {
        $user = $this->createSubscriberUser(withAiPermission: true);
        $subscriberId = (int) $user->subscriber->id;
        $videoPath = 'ai/generated-videos/user-' . $user->id . '/2026/demo.mp4';

        $generation = AiVideoGeneration::query()->create([
            'subscriber_id' => $subscriberId,
            'user_id' => $user->id,
            'title' => 'Готовое видео',
        ]);

        AiVideoGenerationTask::query()->create([
            'video_generation_id' => $generation->id,
            'subscriber_id' => $subscriberId,
            'user_id' => $user->id,
            'external_request_id' => 'req-done',
            'task_type' => 'generate_video',
            'prompt' => 'Prompt',
            'duration' => 5,
            'resolution' => '480p',
            'status' => AiVideoGenerationTask::STATUS_DONE,
            'result_video' => [
                'path' => $videoPath,
                'signed_url' => '/panel/ai/media/' . rawurlencode($videoPath),
            ],
        ]);

        $response = $this->actingAs($user)
            ->getJson('/panel/ai/video/generations/' . $generation->uuid)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.uuid', $generation->uuid)
            ->assertJsonPath('data.tasks.0.status', 'done');

        $videoUrl = (string) $response->json('data.tasks.0.video.url');
        $this->assertSame(
            '/panel/ai/media/generated-videos/user-' . $user->id . '/2026/demo.mp4',
            $videoUrl,
        );
    }

    public function test_subscriber_can_open_generation_with_tasks(): void
    {
        $user = $this->createSubscriberUser(withAiPermission: true);
        $subscriberId = (int) $user->subscriber->id;

        $generation = AiVideoGeneration::query()->create([
            'subscriber_id' => $subscriberId,
            'user_id' => $user->id,
            'title' => 'Открыть генерацию',
        ]);

        AiVideoGenerationTask::query()->create([
            'video_generation_id' => $generation->id,
            'subscriber_id' => $subscriberId,
            'user_id' => $user->id,
            'external_request_id' => 'req-open',
            'task_type' => 'generate_video',
            'prompt' => 'Prompt',
            'duration' => 5,
            'resolution' => '480p',
            'status' => AiVideoGenerationTask::STATUS_PENDING,
        ]);

        $this->actingAs($user)
            ->getJson('/panel/ai/video/generations/' . $generation->uuid)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $generation->id)
            ->assertJsonPath('data.uuid', $generation->uuid)
            ->assertJsonCount(1, 'data.tasks')
            ->assertJsonPath('data.tasks.0.request_id', 'req-open')
            ->assertJsonPath('data.tasks.0.status', 'pending');
    }

    public function test_delete_generation_removes_tasks_and_media_files(): void
    {
        Storage::fake('private');

        $user = $this->createSubscriberUser(withAiPermission: true);
        $subscriberId = (int) $user->subscriber->id;

        $videoPath = 'ai/generated-videos/user-' . $user->id . '/2026/demo.mp4';
        $imagePath = 'ai/source-images/user-' . $user->id . '/2026/demo.jpg';
        Storage::disk('private')->put($videoPath, 'video-binary');
        Storage::disk('private')->put($imagePath, 'image-binary');

        $generation = AiVideoGeneration::query()->create([
            'subscriber_id' => $subscriberId,
            'user_id' => $user->id,
            'title' => 'Удалить меня',
        ]);

        AiVideoGenerationTask::query()->create([
            'video_generation_id' => $generation->id,
            'subscriber_id' => $subscriberId,
            'user_id' => $user->id,
            'external_request_id' => 'req-delete',
            'task_type' => 'generate_video_from_image',
            'prompt' => 'Prompt',
            'duration' => 5,
            'resolution' => '480p',
            'status' => AiVideoGenerationTask::STATUS_DONE,
            'source_images' => [[
                'path' => $imagePath,
                'url_preview' => '/panel/ai/media/' . $imagePath,
            ]],
            'result_video' => [
                'path' => $videoPath,
                'url' => '/panel/ai/media/' . $videoPath,
            ],
        ]);

        $this->actingAs($user)
            ->deleteJson('/panel/ai/video/generations/' . $generation->uuid)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('ai_video_generations', ['id' => $generation->id]);
        $this->assertDatabaseMissing('ai_video_generation_tasks', ['video_generation_id' => $generation->id]);
        Storage::disk('private')->assertMissing($videoPath);
        Storage::disk('private')->assertMissing($imagePath);
    }

    public function test_subscriber_cannot_delete_foreign_generation(): void
    {
        $owner = $this->createSubscriberUser(withAiPermission: true);
        $intruder = $this->createSubscriberUser(withAiPermission: true);

        $generation = AiVideoGeneration::query()->create([
            'subscriber_id' => (int) $owner->subscriber->id,
            'user_id' => $owner->id,
            'title' => 'Чужая',
        ]);

        $this->actingAs($intruder)
            ->deleteJson('/panel/ai/video/generations/' . $generation->uuid)
            ->assertNotFound();
    }

    public function test_video_start_reserves_credits_and_status_captures_once(): void
    {
        $this->setupCreditBillingSchema();
        (new CreditPricingSeeder())->run();
        $user = $this->createSubscriberUser(withAiPermission: true);
        $this->grantCredits($user, 40);

        $grok = Mockery::mock(GrokVideoApiClient::class);
        $grok->shouldReceive('startGeneration')->once()->andReturn([
            'success' => true,
            'status' => 200,
            'data' => [
                'request_id' => 'req-credits',
                'model' => 'grok-video',
            ],
        ]);
        $grok->shouldReceive('getGeneration')->once()->andReturn([
            'success' => true,
            'status' => 200,
            'data' => [
                'status' => 'done',
                'model' => 'grok-video',
                'video' => [
                    'url' => 'https://example.test/video.mp4',
                    'duration' => 5,
                ],
            ],
        ]);
        $this->app->instance(GrokVideoApiClient::class, $grok);

        $storage = Mockery::mock(AiMediaStorageService::class);
        $storage->shouldReceive('storeVideoByUrlAndGetSignedUrl')->once()->andReturn([
            'path' => 'ai/generated-videos/user-'.$user->id.'/2026/demo.mp4',
            'signed_url' => '/panel/ai/media/generated-videos/user-'.$user->id.'/2026/demo.mp4',
        ]);
        $storage->shouldReceive('resolvePanelMediaUrl')->andReturn(
            '/panel/ai/media/generated-videos/user-'.$user->id.'/2026/demo.mp4'
        );
        $this->app->instance(AiMediaStorageService::class, $storage);

        $this->actingAs($user)
            ->postJson('/panel/ai/video/start', [
                'task_type' => 'generate_video',
                'prompt' => 'Короткое видео товара',
                'duration' => 5,
                'resolution' => '480p',
                'credits' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.request_id', 'req-credits');

        $account = CreditAccount::query()->where('user_id', $user->id)->first();
        $this->assertSame(20, $account?->available());
        $this->assertSame(1, CreditHold::query()->where('user_id', $user->id)->where('status', 'held')->count());

        $this->actingAs($user)
            ->getJson('/panel/ai/video/status/req-credits')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'done');

        $account->refresh();
        $this->assertSame(20, $account->available());
        $this->assertSame(0, CreditHold::query()->where('user_id', $user->id)->where('status', 'held')->count());
        $this->assertSame(1, CreditLedger::query()->where('user_id', $user->id)->where('type', 'capture')->count());

        $this->actingAs($user)
            ->getJson('/panel/ai/video/status/req-credits')
            ->assertOk()
            ->assertJsonPath('data.status', 'done');

        $this->assertSame(1, CreditLedger::query()->where('user_id', $user->id)->where('type', 'capture')->count());
    }

    public function test_video_start_without_credits_does_not_call_provider(): void
    {
        $this->setupCreditBillingSchema();
        (new CreditPricingSeeder())->run();
        $user = $this->createSubscriberUser(withAiPermission: true);

        $grok = Mockery::mock(GrokVideoApiClient::class);
        $grok->shouldNotReceive('startGeneration');
        $this->app->instance(GrokVideoApiClient::class, $grok);

        $response = $this->actingAs($user)
            ->postJson('/panel/ai/video/start', [
                'task_type' => 'generate_video',
                'prompt' => 'Короткое видео товара',
                'duration' => 5,
                'resolution' => '720p',
            ])
            ->assertOk()
            ->assertJsonPath('success', false);

        $this->assertStringContainsString('Недостаточно кредитов', (string) $response->json('messages.0'));
    }

    public function test_video_start_maps_payload_too_large_to_user_message(): void
    {
        $this->setupCreditBillingSchema();
        (new CreditPricingSeeder())->run();
        $user = $this->createSubscriberUser(withAiPermission: true);
        $this->grantCredits($user, 40);

        $grok = Mockery::mock(GrokVideoApiClient::class)->makePartial();
        $grok->shouldReceive('startGeneration')->once()->andReturn([
            'success' => false,
            'status' => 413,
            'messages' => ['The POST data is too large'],
            'data' => [],
        ]);
        $this->app->instance(GrokVideoApiClient::class, $grok);

        $storage = Mockery::mock(AiMediaStorageService::class);
        $storage->shouldReceive('isPanelMediaInput')->andReturn(false);
        $storage->shouldReceive('storeImageAndGetSignedUrl')->once()->andReturn([
            'path' => 'ai/source-images/user-'.$user->id.'/2026/demo.png',
            'signed_url' => '/panel/ai/media/source-images/user-'.$user->id.'/2026/demo.png',
            'url_preview' => '/panel/ai/media/source-images/user-'.$user->id.'/2026/demo.png',
            'mime_type' => 'image/png',
            'size' => 70,
        ]);
        $this->app->instance(AiMediaStorageService::class, $storage);

        $response = $this->actingAs($user)
            ->postJson('/panel/ai/video/start', [
                'task_type' => 'generate_video_from_image',
                'prompt' => 'Анимация товара',
                'duration' => 5,
                'resolution' => '480p',
                'image' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            ])
            ->assertOk()
            ->assertJsonPath('success', false);

        $this->assertSame(
            'Изображение слишком большое для создания видео. Загрузите файл меньшего размера.',
            $response->json('messages.0')
        );

        $this->assertDatabaseHas('ai_video_generation_tasks', [
            'user_id' => $user->id,
            'status' => AiVideoGenerationTask::STATUS_FAILED,
            'error_message' => 'Изображение слишком большое для создания видео. Загрузите файл меньшего размера.',
        ]);
    }

    public function test_open_failed_scene_without_request_id_returns_all_source_images(): void
    {
        $user = $this->createSubscriberUser(withAiPermission: true);
        $subscriberId = (int) $user->subscriber->id;

        $generation = AiVideoGeneration::query()->create([
            'subscriber_id' => $subscriberId,
            'user_id' => $user->id,
            'title' => 'Сцена из трёх кадров',
        ]);

        $sourceImages = [];
        for ($index = 1; $index <= 3; $index++) {
            $path = 'ai/source-images/user-'.$user->id.'/2026/scene-'.$index.'.jpg';
            $sourceImages[] = [
                'path' => $path,
                'url_preview' => '/panel/ai/media/source-images/user-'.$user->id.'/2026/scene-'.$index.'.jpg',
            ];
        }

        AiVideoGenerationTask::query()->create([
            'video_generation_id' => $generation->id,
            'subscriber_id' => $subscriberId,
            'user_id' => $user->id,
            'external_request_id' => null,
            'task_type' => 'generate_video_from_scene',
            'prompt' => 'Сцена с тремя референсами',
            'duration' => 5,
            'resolution' => '480p',
            'status' => AiVideoGenerationTask::STATUS_FAILED,
            'source_images' => $sourceImages,
            'error_message' => 'Ошибка при работе. Попробуйте позже.',
        ]);

        $response = $this->actingAs($user)
            ->getJson('/panel/ai/video/generations/'.$generation->uuid)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data.tasks')
            ->assertJsonPath('data.tasks.0.task_type', 'generate_video_from_scene')
            ->assertJsonPath('data.tasks.0.status', 'error')
            ->assertJsonCount(3, 'data.tasks.0.images');

        $this->assertSame(
            '/panel/ai/media/source-images/user-'.$user->id.'/2026/scene-1.jpg',
            $response->json('data.tasks.0.images.0')
        );
        $this->assertSame(
            '/panel/ai/media/source-images/user-'.$user->id.'/2026/scene-3.jpg',
            $response->json('data.tasks.0.images.2')
        );
    }

    public function test_scene_start_stores_all_source_images_in_history(): void
    {
        Storage::fake('private');
        $this->setupCreditBillingSchema();
        (new CreditPricingSeeder())->run();
        $user = $this->createSubscriberUser(withAiPermission: true);
        $this->grantCredits($user, 40);

        $grok = Mockery::mock(GrokVideoApiClient::class);
        $grok->shouldReceive('startGeneration')->once()->andReturn([
            'success' => true,
            'status' => 200,
            'data' => [
                'request_id' => 'req-scene-images',
                'model' => 'grok-video',
            ],
        ]);
        $this->app->instance(GrokVideoApiClient::class, $grok);

        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

        $start = $this->actingAs($user)
            ->postJson('/panel/ai/video/reference/start', [
                'prompt' => 'Сцена из трёх изображений',
                'duration' => 5,
                'resolution' => '480p',
                'images' => [$png, $png, $png],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $generationUuid = (string) $start->json('data.generation_uuid');
        $this->assertNotSame('', $generationUuid);

        $task = AiVideoGenerationTask::query()
            ->where('user_id', $user->id)
            ->where('task_type', 'generate_video_from_scene')
            ->latest('id')
            ->first();

        $this->assertNotNull($task);
        $this->assertCount(3, $task->source_images ?? []);
        foreach ($task->source_images as $sourceImage) {
            $this->assertNotSame('', (string) ($sourceImage['path'] ?? ''));
            Storage::disk('private')->assertExists((string) $sourceImage['path']);
        }

        $this->actingAs($user)
            ->getJson('/panel/ai/video/generations/'.$generationUuid)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(3, 'data.tasks.0.images');
    }

    public function test_scene_start_accepts_panel_media_urls_from_history(): void
    {
        Storage::fake('private');
        $this->setupCreditBillingSchema();
        (new CreditPricingSeeder())->run();
        $user = $this->createSubscriberUser(withAiPermission: true);
        $this->grantCredits($user, 40);

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $this->assertNotFalse($png);

        $images = [];
        for ($index = 1; $index <= 3; $index++) {
            $path = 'ai/source-images/user-'.$user->id.'/2026/history-'.$index.'.png';
            Storage::disk('private')->put($path, $png);
            $images[] = '/panel/ai/media/source-images/user-'.$user->id.'/2026/history-'.$index.'.png';
        }

        $grok = Mockery::mock(GrokVideoApiClient::class);
        $grok->shouldReceive('startGeneration')
            ->once()
            ->withArgs(function (string $taskType, string $prompt, array $options) use ($images): bool {
                return $taskType === 'generate_video_from_image'
                    && $prompt === 'Повтор сцены из истории'
                    && ($options['reference_images'] ?? []) === $images;
            })
            ->andReturn([
                'success' => true,
                'status' => 200,
                'data' => [
                    'request_id' => 'req-history-scene',
                    'model' => 'grok-video',
                ],
            ]);
        $this->app->instance(GrokVideoApiClient::class, $grok);

        $this->actingAs($user)
            ->postJson('/panel/ai/video/reference/start', [
                'prompt' => 'Повтор сцены из истории',
                'duration' => 5,
                'resolution' => '480p',
                'images' => $images,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.request_id', 'req-history-scene');
    }

    public function test_scene_start_rejects_foreign_panel_media_url(): void
    {
        $this->setupCreditBillingSchema();
        (new CreditPricingSeeder())->run();
        $user = $this->createSubscriberUser(withAiPermission: true);
        $this->grantCredits($user, 40);

        $grok = Mockery::mock(GrokVideoApiClient::class);
        $grok->shouldNotReceive('startGeneration');
        $this->app->instance(GrokVideoApiClient::class, $grok);

        $response = $this->actingAs($user)
            ->postJson('/panel/ai/video/reference/start', [
                'prompt' => 'Чужое изображение',
                'duration' => 5,
                'resolution' => '480p',
                'images' => ['/panel/ai/media/source-images/user-999/2026/foreign.png'],
            ])
            ->assertOk()
            ->assertJsonPath('success', false);

        $this->assertStringContainsString('Изображение не найдено', (string) $response->json('messages.0'));
    }

    public function test_edit_start_rejects_video_longer_than_limit_before_grok(): void
    {
        Storage::fake('private');
        $this->setupCreditBillingSchema();
        (new CreditPricingSeeder())->run();
        $user = $this->createSubscriberUser(withAiPermission: true);
        $this->grantCredits($user, 80);

        $grok = Mockery::mock(GrokVideoApiClient::class);
        $grok->shouldNotReceive('startEdit');
        $this->app->instance(GrokVideoApiClient::class, $grok);

        $file = UploadedFile::fake()->createWithContent(
            'long.mp4',
            Mp4BoxBuilder::build(['duration_units' => 9000]),
        );

        $response = $this->actingAs($user)
            ->post('/panel/ai/video/edit/start', [
                'prompt' => 'Добавь дождь',
                'video' => $file,
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('success', false);

        $this->assertSame(
            'Ролик слишком длинный. Нужен файл не длиннее 8 секунд.',
            $response->json('messages.0')
        );
        $this->assertDatabaseMissing('ai_video_generation_tasks', [
            'user_id' => $user->id,
            'task_type' => 'edit_video',
        ]);
    }

    public function test_edit_start_stores_source_video_and_returns_it_in_history(): void
    {
        Storage::fake('private');
        $this->setupCreditBillingSchema();
        (new CreditPricingSeeder())->run();
        $user = $this->createSubscriberUser(withAiPermission: true);
        $this->grantCredits($user, 80);

        $grok = Mockery::mock(GrokVideoApiClient::class);
        $grok->shouldReceive('startEdit')->once()->andReturn([
            'success' => true,
            'status' => 200,
            'data' => [
                'request_id' => 'req-edit-1',
                'model' => 'grok-imagine-video',
            ],
        ]);
        $this->app->instance(GrokVideoApiClient::class, $grok);

        $file = UploadedFile::fake()->createWithContent(
            'clip.mp4',
            Mp4BoxBuilder::build(['duration_units' => 5000, 'height' => 360]),
        );

        $start = $this->actingAs($user)
            ->post('/panel/ai/video/edit/start', [
                'prompt' => 'Добавь солнечный свет',
                'video' => $file,
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.request_id', 'req-edit-1');

        $generationUuid = (string) $start->json('data.generation_uuid');
        $task = AiVideoGenerationTask::query()
            ->where('user_id', $user->id)
            ->where('task_type', 'edit_video')
            ->latest('id')
            ->first();

        $this->assertNotNull($task);
        $this->assertSame(5, (int) $task->duration);
        $this->assertSame('480p', $task->resolution);
        $this->assertNotEmpty($task->source_images[0]['path'] ?? null);
        Storage::disk('private')->assertExists((string) $task->source_images[0]['path']);

        $open = $this->actingAs($user)
            ->getJson('/panel/ai/video/generations/'.$generationUuid)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.tasks.0.task_type', 'edit_video');

        $this->assertNotSame('', (string) $open->json('data.tasks.0.source_video'));
        $this->assertNull($open->json('data.tasks.0.images'));
    }

    public function test_edit_start_accepts_panel_media_url_from_history(): void
    {
        Storage::fake('private');
        $this->setupCreditBillingSchema();
        (new CreditPricingSeeder())->run();
        $user = $this->createSubscriberUser(withAiPermission: true);
        $this->grantCredits($user, 80);

        $binary = Mp4BoxBuilder::build(['duration_units' => 4000, 'height' => 720]);
        $path = 'ai/source-videos/user-'.$user->id.'/2026/history.mp4';
        Storage::disk('private')->put($path, $binary);
        $panelUrl = '/panel/ai/media/source-videos/user-'.$user->id.'/2026/history.mp4';

        $grok = Mockery::mock(GrokVideoApiClient::class);
        $grok->shouldReceive('startEdit')
            ->once()
            ->withArgs(function (string $prompt, array $options) use ($panelUrl): bool {
                return $prompt === 'Повтор правки из истории'
                    && ($options['video'] ?? '') === $panelUrl;
            })
            ->andReturn([
                'success' => true,
                'status' => 200,
                'data' => ['request_id' => 'req-edit-hist'],
            ]);
        $this->app->instance(GrokVideoApiClient::class, $grok);

        $this->actingAs($user)
            ->postJson('/panel/ai/video/edit/start', [
                'prompt' => 'Повтор правки из истории',
                'video' => $panelUrl,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.request_id', 'req-edit-hist');
    }

    public function test_edit_start_rejects_foreign_panel_video(): void
    {
        $this->setupCreditBillingSchema();
        (new CreditPricingSeeder())->run();
        $user = $this->createSubscriberUser(withAiPermission: true);
        $this->grantCredits($user, 80);

        $grok = Mockery::mock(GrokVideoApiClient::class);
        $grok->shouldNotReceive('startEdit');
        $this->app->instance(GrokVideoApiClient::class, $grok);

        $response = $this->actingAs($user)
            ->postJson('/panel/ai/video/edit/start', [
                'prompt' => 'Чужой ролик',
                'video' => '/panel/ai/media/source-videos/user-999/2026/foreign.mp4',
            ])
            ->assertOk()
            ->assertJsonPath('success', false);

        $this->assertStringContainsString('Ролик не найден', (string) $response->json('messages.0'));
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

    private function setupAiVideoGenerationSchema(): void
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

        if (! Schema::hasTable('ai_video_generations')) {
            Schema::create('ai_video_generations', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->nullable()->unique();
                $table->unsignedBigInteger('subscriber_id')->index();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('title', 120)->nullable();
                $table->timestamps();
            });
        } elseif (! Schema::hasColumn('ai_video_generations', 'uuid')) {
            Schema::table('ai_video_generations', function (Blueprint $table) {
                $table->uuid('uuid')->nullable()->unique()->after('id');
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

        if (! Schema::hasTable('ai_video_generation_tasks')) {
            Schema::create('ai_video_generation_tasks', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('video_generation_id')->index();
                $table->unsignedBigInteger('subscriber_id')->index();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('external_request_id', 128)->nullable()->index();
                $table->string('task_type', 64);
                $table->text('prompt');
                $table->unsignedTinyInteger('duration')->default(5);
                $table->string('resolution', 16)->default('480p');
                $table->string('aspect_ratio', 16)->nullable();
                $table->json('source_images')->nullable();
                $table->string('status', 32)->default('pending');
                $table->json('result_video')->nullable();
                $table->text('error_message')->nullable();
                $table->string('model', 128)->nullable();
                $table->timestamp('limit_consumed_at')->nullable();
                $table->string('credit_idempotency_key')->nullable();
                $table->timestamps();
            });
        } elseif (! Schema::hasColumn('ai_video_generation_tasks', 'credit_idempotency_key')) {
            Schema::table('ai_video_generation_tasks', function (Blueprint $table) {
                $table->string('credit_idempotency_key')->nullable();
            });
        }
    }
}