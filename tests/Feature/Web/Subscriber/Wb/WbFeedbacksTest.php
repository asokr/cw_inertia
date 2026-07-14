<?php

namespace Tests\Feature\Web\Subscriber\Wb;

use App\Models\Subscribers\Subscribers;
use App\Models\Subscribers\Wb\Feedbacks\FeedbacksClients;
use App\Models\Subscribers\Wb\Feedbacks\FeedbacksTemplates;
use App\Models\User;
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
                ->where('feedbacksError', fn ($error) => $error === null
                    || ! str_contains(mb_strtolower((string) $error), 'client id field is required')
                    && ! str_contains(mb_strtolower((string) $error), 'skip field is required')));
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