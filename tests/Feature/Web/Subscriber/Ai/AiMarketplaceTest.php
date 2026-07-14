<?php

namespace Tests\Feature\Web\Subscriber\Ai;

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

class AiMarketplaceTest extends WebAuthTestCase
{
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
        $this->get('/panel/ai/text')->assertUnauthorized();
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
                ->has('limits')
                ->where('limits.text', 10)
                ->where('limits.image', 5)
                ->where('limits.video', 30));

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

    public function test_refresh_limits_returns_value(): void
    {
        $user = $this->createSubscriberUser(withAiPermission: true);

        $this->actingAs($user)
            ->postJson('/panel/ai/limits', ['limit' => 'ai_text_query'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', 10);
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