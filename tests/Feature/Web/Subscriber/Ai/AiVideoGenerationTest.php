<?php

namespace Tests\Feature\Web\Subscriber\Ai;

use App\Models\AiVideoGeneration;
use App\Models\AiVideoGenerationTask;
use App\Models\Subscribers\Subscribers;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Web\Auth\WebAuthTestCase;

class AiVideoGenerationTest extends WebAuthTestCase
{
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
                'signed_url' => '/api/subscriber/ai/media/' . rawurlencode($videoPath),
            ],
        ]);

        $this->actingAs($user)
            ->getJson('/panel/ai/video/generations/' . $generation->id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.tasks.0.status', 'done')
            ->assertJsonPath('data.tasks.0.video.url', '/panel/ai/media/generated-videos/user-' . $user->id . '/2026/demo.mp4');
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
            ->getJson('/panel/ai/video/generations/' . $generation->id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $generation->id)
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
            ->deleteJson('/panel/ai/video/generations/' . $generation->id)
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
            ->deleteJson('/panel/ai/video/generations/' . $generation->id)
            ->assertNotFound();
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
                $table->unsignedBigInteger('subscriber_id')->index();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('title', 120)->nullable();
                $table->timestamps();
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
                $table->timestamps();
            });
        }
    }
}