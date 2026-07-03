<?php

namespace Tests\Feature\Web\Admin;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Web\Auth\WebAuthTestCase;

class AdminCwPageRoutesTest extends WebAuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setupCwPageSchema();

        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'blog.view', 'guard_name' => 'web']);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function test_guest_cannot_access_cw_page(): void
    {
        $this->get('/cw-page')->assertNotFound();
        $this->get('/cw-page/subscribers')->assertNotFound();
    }

    public function test_subscriber_cannot_access_cw_page(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);
        $user->assignRole('Подписчик');

        $this->actingAs($user)->get('/cw-page')->assertNotFound();
        $this->actingAs($user)->get('/cw-page/subscribers')->assertNotFound();
    }

    public function test_cw_page_renders_dashboard_for_super_admin(): void
    {
        $user = $this->makeSuperAdmin();

        $this->actingAs($user)
            ->get('/cw-page')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Dashboard/Index')
                ->where('isSuperAdmin', true)
                ->where('canViewBlog', true));
    }

    public function test_cw_page_renders_dashboard_for_blog_editor(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);
        $user->givePermissionTo('blog.view');

        $this->actingAs($user)
            ->get('/cw-page')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Dashboard/Index')
                ->where('isSuperAdmin', false)
                ->where('canViewBlog', true));
    }

    public function test_super_admin_can_open_all_cw_page_index_routes(): void
    {
        $user = $this->makeSuperAdmin();

        $routes = [
            ['/cw-page/subscribers', 'Admin/Subscribers/Index'],
            ['/cw-page/plans', 'Admin/Plans/Index'],
            ['/cw-page/plans/create', 'Admin/Plans/Form'],
            ['/cw-page/extra-limits', 'Admin/ExtraLimits/Index'],
            ['/cw-page/payments', 'Admin/Payments/Index'],
            ['/cw-page/coupons', 'Admin/Coupons/Index'],
            ['/cw-page/sent-emails', 'Admin/SentEmails/Index'],
            ['/cw-page/roles', 'Admin/Roles/Index'],
            ['/cw-page/users', 'Admin/Users/Index'],
            ['/cw-page/services/feedbacks/cabinets', 'Admin/Services/Feedbacks/Cabinets/Index'],
            ['/cw-page/services/feedbacks/ai-answers', 'Admin/Services/Feedbacks/AiAnswers/Index'],
            ['/cw-page/services/repricer/cabinets', 'Admin/Services/Repricer/Cabinets/Index'],
            ['/cw-page/services/repricer/nmids', 'Admin/Services/Repricer/Nmids/Index'],
            ['/cw-page/services/ai-cabinet/cabinets', 'Admin/Services/AiCabinet/Cabinets/Index'],
            ['/cw-page/services/ai-cabinet/prompts', 'Admin/Services/AiCabinet/Prompts/Index'],
            ['/cw-page/services/ai/marketplace-logs', 'Admin/Services/Ai/MarketplaceLogs/Index'],
            ['/cw-page/services/ai/costs-archive', 'Admin/Services/Ai/CostsArchive/Index'],
            ['/cw-page/wb/api-usage', 'Admin/Wb/ApiUsage/Index'],
            ['/cw-page/wb/api-usage/seller-42/logs', 'Admin/Wb/ApiUsage/Logs'],
        ];

        foreach ($routes as [$path, $component]) {
            $this->actingAs($user)
                ->get($path)
                ->assertOk()
                ->assertInertia(fn ($page) => $page->component($component));
        }
    }

    public function test_blog_editor_can_open_blog_routes(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);
        $user->givePermissionTo('blog.view');

        $routes = [
            ['/cw-page/blog/posts', 'Admin/Blog/Posts/Index'],
            ['/cw-page/blog/categories', 'Admin/Blog/Categories/Index'],
            ['/cw-page/blog/tags', 'Admin/Blog/Tags/Index'],
        ];

        foreach ($routes as [$path, $component]) {
            $this->actingAs($user)
                ->get($path)
                ->assertOk()
                ->assertInertia(fn ($page) => $page->component($component));
        }
    }

    private function makeSuperAdmin(): User
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);
        $user->assignRole('super-admin');

        return $user;
    }

    private function setupCwPageSchema(): void
    {
        $tables = [
            'posts' => function (Blueprint $table) {
                $table->id();
                $table->string('title');
                $table->string('slug')->unique();
                $table->longText('content')->nullable();
                $table->text('excerpt')->nullable();
                $table->string('cover_image')->nullable();
                $table->string('status')->default('draft');
                $table->timestamp('published_at')->nullable();
                $table->unsignedInteger('views_count')->default(0);
                $table->string('seo_title')->nullable();
                $table->text('seo_description')->nullable();
                $table->json('seo_keywords')->nullable();
                $table->unsignedBigInteger('author_id')->nullable();
                $table->timestamps();
            },
            'categories' => function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->timestamps();
            },
            'tags' => function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->timestamps();
            },
            'post_category' => function (Blueprint $table) {
                $table->unsignedBigInteger('post_id');
                $table->unsignedBigInteger('category_id');
            },
            'post_tag' => function (Blueprint $table) {
                $table->unsignedBigInteger('post_id');
                $table->unsignedBigInteger('tag_id');
            },
            'subscribers_plans' => function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->decimal('price', 10, 2)->default(0);
                $table->unsignedInteger('duration')->default(30);
                $table->text('description')->nullable();
                $table->json('limits_plan')->nullable();
                $table->json('limits_month')->nullable();
                $table->json('permissions')->nullable();
                $table->unsignedTinyInteger('status')->default(1);
                $table->unsignedTinyInteger('hidden')->default(0);
                $table->timestamps();
            },
            'subscribers_subscriptions' => function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('subscribers_id');
                $table->unsignedBigInteger('plan_id');
                $table->json('limits_plan')->nullable();
                $table->json('extra_limits_plan')->nullable();
                $table->json('limits_month')->nullable();
                $table->json('extra_limits_month')->nullable();
                $table->timestamp('start_date')->nullable();
                $table->timestamp('end_date')->nullable();
                $table->unsignedTinyInteger('status')->default(1);
                $table->timestamps();
            },
            'extra_limits' => function (Blueprint $table) {
                $table->id();
                $table->string('limit_name');
                $table->unsignedInteger('quantity')->default(0);
                $table->decimal('price', 10, 2)->default(0);
                $table->unsignedInteger('order')->default(0);
                $table->timestamps();
            },
            'payments_transactions' => function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->decimal('amount', 12, 2)->default(0);
                $table->string('description')->nullable();
                $table->string('system')->nullable();
                $table->string('system_id')->nullable();
                $table->string('status')->nullable();
                $table->timestamps();
            },
            'ai_costs' => function (Blueprint $table) {
                $table->id();
                $table->string('provider')->nullable();
                $table->decimal('cost', 12, 6)->default(0);
                $table->date('date')->nullable();
                $table->timestamps();
            },
            'ai_request_logs' => function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->unsignedBigInteger('subscriber_id')->nullable();
                $table->string('task_type')->nullable();
                $table->string('marketplace')->nullable();
                $table->string('provider')->nullable();
                $table->string('model')->nullable();
                $table->integer('status_code')->nullable();
                $table->text('response_text')->nullable();
                $table->json('request_payload')->nullable();
                $table->json('provider_response_payload')->nullable();
                $table->json('response_images')->nullable();
                $table->json('response_videos')->nullable();
                $table->string('response_type')->nullable();
                $table->string('generation_status')->nullable();
                $table->string('external_request_id')->nullable();
                $table->unsignedInteger('images_count')->nullable();
                $table->unsignedInteger('videos_count')->nullable();
                $table->unsignedInteger('input_tokens')->nullable();
                $table->unsignedInteger('output_tokens')->nullable();
                $table->unsignedInteger('prompt_tokens')->nullable();
                $table->unsignedInteger('candidates_tokens')->nullable();
                $table->unsignedInteger('total_tokens')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('limit_consumed_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            },
            'wb_ai_cabinet_analyzer_cabinets' => function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('name')->nullable();
                $table->text('apikey')->nullable();
                $table->timestamps();
            },
            'wb_ai_cabinet_analyzer_templates' => function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->text('description')->nullable();
                $table->longText('system_prompt');
                $table->unsignedInteger('sort_order')->default(100);
                $table->boolean('is_active')->default(true);
                $table->string('response_format')->default('json');
                $table->timestamps();
            },
            'wb_api_usage_stats' => function (Blueprint $table) {
                $table->id();
                $table->date('stat_date');
                $table->text('api_key')->nullable();
                $table->string('api_key_hash')->nullable();
                $table->unsignedInteger('requests_count')->default(0);
                $table->string('legal_entity')->nullable();
                $table->string('seller_id')->nullable();
                $table->timestamp('legal_entity_synced_at')->nullable();
                $table->timestamps();
            },
            'wb_api_request_logs' => function (Blueprint $table) {
                $table->id();
                $table->string('api_key')->nullable();
                $table->string('api_key_hash')->nullable();
                $table->string('method')->nullable();
                $table->string('endpoint')->nullable();
                $table->json('request_data')->nullable();
                $table->integer('response_code')->nullable();
                $table->timestamp('created_at')->nullable();
            },
            'coupons' => function (Blueprint $table) {
                $table->id();
                $table->string('code');
                $table->unsignedInteger('limit')->nullable();
                $table->string('type');
                $table->decimal('value', 12, 2);
                $table->timestamp('start_date')->nullable();
                $table->timestamp('end_date')->nullable();
                $table->timestamps();
            },
            'sent_emails' => function (Blueprint $table) {
                $table->id();
                $table->string('to')->nullable();
                $table->string('subject')->nullable();
                $table->longText('body')->nullable();
                $table->string('type')->nullable();
                $table->string('status')->nullable();
                $table->text('error_message')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();
            },
            'subs_wb_feedbacks_clients' => function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('subscriber_id')->nullable();
                $table->string('name')->nullable();
                $table->json('brands')->nullable();
                $table->string('bot_status')->nullable();
                $table->string('ai_status')->nullable();
                $table->json('ai_ratings')->nullable();
                $table->timestamps();
            },
            'wb_feedbacks_reviews' => function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('cabinet_id')->nullable();
                $table->unsignedTinyInteger('rating')->nullable();
                $table->string('subject_name')->nullable();
                $table->unsignedBigInteger('product_id')->nullable();
                $table->text('content')->nullable();
                $table->timestamps();
            },
            'wb_feedbacks_bot_responses' => function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('review_id')->nullable();
                $table->text('response_text')->nullable();
                $table->timestamps();
            },
            'wb_repricer_cabinets' => function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('name')->nullable();
                $table->timestamps();
            },
            'wb_repricer_settings' => function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('cabinet_id');
                $table->unsignedBigInteger('nmID')->nullable();
                $table->timestamps();
            },
            'wb_repricer_logs' => function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('nmID')->nullable();
                $table->string('type')->nullable();
                $table->text('message')->nullable();
                $table->timestamps();
            },
        ];

        foreach ($tables as $name => $callback) {
            if (! Schema::hasTable($name)) {
                Schema::create($name, $callback);
            }
        }
    }
}