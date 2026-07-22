<?php

namespace Tests\Feature\Web\Subscriber\Wb;

use App\Models\Subscribers\Subscribers;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\Subscribers\Wb\Feedbacks\BotResponse;
use App\Models\Subscribers\Wb\Feedbacks\FeedbacksClients;
use App\Models\Subscribers\Wb\Feedbacks\FeedbacksTemplates;
use App\Models\Subscribers\Wb\Feedbacks\Review;
use App\Models\User;
use App\Services\Subscriber\Ai\SubscriberAiTextService;
use Illuminate\Http\Request;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Web\Auth\WebAuthTestCase;

class WbFeedbacksTest extends WebAuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setupFeedbacksSchema();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::firstOrCreate([
            'name' => 'subscriber wb feedbacks',
            'guard_name' => 'web',
        ]);
    }

    public function test_guest_cannot_access_wb_feedbacks_index(): void
    {
        $this->get('/panel/wb/feedbacks')->assertUnauthorized();
    }

    public function test_user_without_permission_cannot_access_wb_feedbacks_index(): void
    {
        $user = $this->createSubscriberUser();

        $this->actingAs($user)
            ->get('/panel/wb/feedbacks')
            ->assertForbidden();
    }

    public function test_subscriber_with_permission_can_access_index(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);

        $this->actingAs($user)
            ->get('/panel/wb/feedbacks')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Wb/Feedbacks/Index')
                ->has('cabinets'));
    }

    public function test_index_lists_owned_cabinets(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'Test Cabinet');

        $this->actingAs($user)
            ->get('/panel/wb/feedbacks')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('cabinets.0.name', 'Test Cabinet')
                ->where('cabinets.0.id', $cabinet->id));
    }

    public function test_client_show_renders_for_owner(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'Client Cabinet');

        $this->actingAs($user)
            ->get("/panel/wb/feedbacks/clients/{$cabinet->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Wb/Feedbacks/Client/Show')
                ->where('client.id', $cabinet->id)
                ->has('feedbacks')
                ->has('feedbacksMeta')
                ->has('filters')
                ->where('feedbacksMeta.brand_filter_active', false)
                ->where('feedbacksError', fn ($error) => $error === null
                    || ! str_contains(mb_strtolower((string) $error), 'client id field is required')
                    && ! str_contains(mb_strtolower((string) $error), 'skip field is required')));
    }

    public function test_client_show_exposes_brand_filter_from_cabinet(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'Brands Cabinet');
        $cabinet->brands = 'Nike, Adidas';
        $cabinet->save();

        $this->actingAs($user)
            ->get("/panel/wb/feedbacks/clients/{$cabinet->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Wb/Feedbacks/Client/Show')
                ->where('feedbacksMeta.brand_filter_active', true)
                ->where('feedbacksMeta.brands', ['Nike', 'Adidas'])
                ->where('client.brands', 'Nike, Adidas'));
    }

    public function test_update_ai_settings_enables_auto_replies(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'AI Cabinet');

        $this->actingAs($user)
            ->postJson("/panel/wb/feedbacks/clients/{$cabinet->id}/ai", [
                'status' => 1,
                'ratings' => [4, 5],
                'review_type' => [],
            ])
            ->assertOk()
            ->assertJson([
                'success' => true,
            ]);

        $cabinet->refresh();

        $this->assertSame(1, (int) $cabinet->ai_status);
        $this->assertSame([4, 5], $cabinet->ai_ratings);
    }

    public function test_client_show_forbidden_for_foreign_cabinet(): void
    {
        $owner = $this->createSubscriberUser(withPermission: true);
        $intruder = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($owner, 'Foreign');

        $this->actingAs($intruder)
            ->get("/panel/wb/feedbacks/clients/{$cabinet->id}")
            ->assertForbidden();
    }

    public function test_answered_reviews_endpoint_returns_data_and_meta(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'Answered Cabinet');

        $withText = $this->createAnsweredReview($cabinet->id, [
            'content' => 'Отличный товар',
            'rating' => 5,
            'product_id' => 111,
            'updated_at' => now()->subMinute(),
        ], 'Спасибо!');

        $withoutText = $this->createAnsweredReview($cabinet->id, [
            'content' => '',
            'rating' => 4,
            'product_id' => 222,
            'updated_at' => now(),
        ], 'Благодарим!');

        $this->actingAs($user)
            ->getJson("/panel/wb/feedbacks/clients/{$cabinet->id}/answered?limit=10")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.limit', 10)
            ->assertJsonPath('meta.offset', 0)
            ->assertJsonPath('meta.has_more', false)
            ->assertJsonCount(2, 'data');

        $this->actingAs($user)
            ->getJson("/panel/wb/feedbacks/clients/{$cabinet->id}/answered?limit=1&offset=0")
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.has_more', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $withoutText->id);

        $this->actingAs($user)
            ->getJson("/panel/wb/feedbacks/clients/{$cabinet->id}/answered?limit=1&offset=1")
            ->assertOk()
            ->assertJsonPath('meta.has_more', false)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $withText->id);

        $this->actingAs($user)
            ->getJson("/panel/wb/feedbacks/clients/{$cabinet->id}/answered?limit=10&has_text=1")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $withText->id);
    }

    public function test_answered_reviews_forbidden_for_foreign_cabinet(): void
    {
        $owner = $this->createSubscriberUser(withPermission: true);
        $intruder = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($owner, 'Foreign Answered');

        $this->actingAs($intruder)
            ->getJson("/panel/wb/feedbacks/clients/{$cabinet->id}/answered")
            ->assertForbidden();
    }

    public function test_templates_page_renders_for_owner(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'Templates Cabinet');

        FeedbacksTemplates::query()->create([
            'client_id' => $cabinet->id,
            'text' => 'Спасибо за отзыв, {username}!',
            'rating' => [4, 5],
        ]);

        $this->actingAs($user)
            ->get("/panel/wb/feedbacks/clients/{$cabinet->id}/templates")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Wb/Feedbacks/Templates/Index')
                ->has('templates', 1));
    }

    public function test_store_template_redirects_and_lists_template(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'Templates Cabinet');

        $this->actingAs($user)
            ->post("/panel/wb/feedbacks/clients/{$cabinet->id}/templates", [
                'text' => 'Спасибо за ваш отзыв, {username}!',
                'minRating' => 4,
                'maxRating' => 5,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('subs_wb_feedbacks_templates', [
            'client_id' => $cabinet->id,
            'text' => 'Спасибо за ваш отзыв, {username}!',
        ]);

        $this->actingAs($user)
            ->get("/panel/wb/feedbacks/clients/{$cabinet->id}/templates")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Wb/Feedbacks/Templates/Index')
                ->has('templates', 1)
                ->where('templates.0.text', 'Спасибо за ваш отзыв, {username}!'));
    }

    public function test_product_stats_page_renders_for_owner(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'Stats Cabinet');

        $this->actingAs($user)
            ->get("/panel/wb/feedbacks/clients/{$cabinet->id}/products/123456")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Wb/Feedbacks/Product/Stats')
                ->where('productId', '123456')
                ->has('months'));
    }

    public function test_generate_ai_passes_prompt_for_json_feedback_request(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'AI Generate Cabinet');
        $subscriber = Subscribers::query()->where('user_id', $user->id)->firstOrFail();

        SubscribersSubscriptions::query()->create([
            'subscribers_id' => $subscriber->id,
            'status' => 1,
            'limits_month' => ['feedbacks_gpt_query' => 5],
        ]);

        $this->mock(SubscriberAiTextService::class, function ($mock): void {
            $mock->shouldReceive('ask')
                ->once()
                ->withArgs(function (Request $request): bool {
                    $prompt = $request->input('prompt');

                    return is_string($prompt)
                        && strlen($prompt) >= 10
                        && str_contains($prompt, 'Это отзыв на товар:')
                        && $request->input('for') === 'feedbacks';
                })
                ->andReturn(response()->json([
                    'success' => true,
                    'messages' => ['Ответ ИИ'],
                    'data' => 'Спасибо за ваш отзыв!',
                ], 200));
        });

        $this->actingAs($user)
            ->postJson("/panel/wb/feedbacks/clients/{$cabinet->id}/ai/generate", [
                'feedback' => [
                    'id' => 'fb-1',
                    'productValuation' => 5,
                    'productDetails' => [
                        'productName' => 'Тестовый товар',
                        'brandName' => 'Test Brand',
                    ],
                ],
                'rating_type' => null,
            ])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'data' => 'Спасибо за ваш отзыв!',
            ]);
    }

    public function test_destroy_cabinet_redirects_with_success(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'Delete Me');

        $this->actingAs($user)
            ->delete("/panel/wb/feedbacks/clients/{$cabinet->id}")
            ->assertRedirect('/panel/wb/feedbacks')
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('subs_wb_feedbacks_clients', ['id' => $cabinet->id]);
    }

    private function createSubscriberUser(bool $withPermission = false): User
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
        ]);

        $user->assignRole('Подписчик');

        if ($withPermission) {
            $user->givePermissionTo('subscriber wb feedbacks');
        }

        Subscribers::query()->create([
            'user_id' => $user->id,
            'status' => 1,
        ]);

        return $user;
    }

    private function createCabinet(User $user, string $name): FeedbacksClients
    {
        $subscriber = Subscribers::query()->where('user_id', $user->id)->firstOrFail();

        return FeedbacksClients::query()->create([
            'subscriber_id' => $subscriber->id,
            'name' => $name,
            'brands' => '',
            'apikey' => 'test-api-key',
            'bot_status' => 0,
            'ai_status' => 0,
        ]);
    }

    /**
     * @param  array<string, mixed>  $reviewAttrs
     */
    private function createAnsweredReview(int $cabinetId, array $reviewAttrs, string $responseText): Review
    {
        $review = Review::query()->create(array_merge([
            'cabinet_id' => $cabinetId,
            'rating' => 5,
            'product_id' => 1000,
            'content' => null,
            'pros' => null,
            'cons' => null,
            'photo_links' => null,
        ], $reviewAttrs));

        BotResponse::query()->create([
            'review_id' => $review->id,
            'response_text' => $responseText,
            'is_ai_response' => 1,
        ]);

        return $review->fresh();
    }

    private function setupFeedbacksSchema(): void
    {
        if (! Schema::hasTable('subs_wb_feedbacks_clients')) {
            Schema::create('subs_wb_feedbacks_clients', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('subscriber_id')->index();
                $table->string('name');
                $table->text('brands')->nullable();
                $table->text('apikey')->nullable();
                $table->unsignedTinyInteger('bot_status')->default(0);
                $table->unsignedTinyInteger('ai_status')->default(0);
                $table->json('ai_ratings')->nullable();
                $table->string('review_type')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('subs_wb_feedbacks_templates')) {
            Schema::create('subs_wb_feedbacks_templates', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('client_id')->index();
                $table->text('text');
                $table->string('rating')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('subscribers_subscriptions')) {
            Schema::create('subscribers_subscriptions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('subscribers_id')->index();
                $table->unsignedBigInteger('plan_id')->nullable();
                $table->unsignedTinyInteger('status')->default(1);
                $table->json('limits_plan')->nullable();
                $table->json('limits_month')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('wb_feedbacks_reviews')) {
            Schema::create('wb_feedbacks_reviews', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('cabinet_id')->nullable();
                $table->unsignedTinyInteger('rating')->nullable();
                $table->unsignedBigInteger('product_id')->nullable();
                $table->text('content')->nullable();
                $table->text('pros')->nullable();
                $table->text('cons')->nullable();
                $table->json('photo_links')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('wb_feedbacks_bot_responses')) {
            Schema::create('wb_feedbacks_bot_responses', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('review_id')->nullable();
                $table->text('response_text')->nullable();
                $table->unsignedTinyInteger('is_ai_response')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('wb_feedbacks_review_product_statistics')) {
            Schema::create('wb_feedbacks_review_product_statistics', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('cabinet_id')->nullable();
                $table->unsignedBigInteger('product_id')->nullable();
                $table->date('date')->nullable();
                $table->json('stat_data')->nullable();
                $table->json('pros_cons_data')->nullable();
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