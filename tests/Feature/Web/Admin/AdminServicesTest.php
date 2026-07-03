<?php

namespace Tests\Feature\Web\Admin;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Web\Auth\WebAuthTestCase;

class AdminServicesTest extends WebAuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setupServicesSchema();

        Role::firstOrCreate([
            'name' => 'super-admin',
            'guard_name' => 'web',
        ]);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function test_pr6_pages_require_super_admin(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('password'),
        ]);

        $routes = [
            '/cw-page/services/ai-cabinet/cabinets',
            '/cw-page/services/ai-cabinet/prompts',
            '/cw-page/services/ai/marketplace-logs',
            '/cw-page/services/ai/costs-archive',
            '/cw-page/wb/api-usage',
            '/cw-page/wb/api-usage/12345/logs',
        ];

        foreach ($routes as $route) {
            $this->actingAs($user)->get($route)->assertForbidden();
        }
    }

    public function test_super_admin_can_open_pr6_pages(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('password'),
        ]);
        $user->assignRole('super-admin');

        $this->actingAs($user)
            ->get('/cw-page/services/ai-cabinet/cabinets')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Services/AiCabinet/Cabinets/Index'));

        $this->actingAs($user)
            ->get('/cw-page/services/ai-cabinet/prompts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Services/AiCabinet/Prompts/Index'));

        $this->actingAs($user)
            ->get('/cw-page/services/ai/marketplace-logs')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Services/Ai/MarketplaceLogs/Index'));

        $this->actingAs($user)
            ->get('/cw-page/services/ai/costs-archive')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Services/Ai/CostsArchive/Index'));

        $this->actingAs($user)
            ->get('/cw-page/wb/api-usage')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Wb/ApiUsage/Index'));

        $this->actingAs($user)
            ->get('/cw-page/wb/api-usage/12345/logs')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Wb/ApiUsage/Logs')
                ->where('sellerId', '12345'));
    }

    private function setupServicesSchema(): void
    {
        if (! Schema::hasTable('ai_costs')) {
            Schema::create('ai_costs', function (Blueprint $table) {
                $table->id();
                $table->string('provider')->nullable();
                $table->decimal('cost', 12, 6)->default(0);
                $table->date('date')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_request_logs')) {
            Schema::create('ai_request_logs', function (Blueprint $table) {
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
            });
        }

        if (! Schema::hasTable('wb_ai_cabinet_analyzer_cabinets')) {
            Schema::create('wb_ai_cabinet_analyzer_cabinets', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('name')->nullable();
                $table->text('apikey')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('wb_ai_cabinet_analyzer_templates')) {
            Schema::create('wb_ai_cabinet_analyzer_templates', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->text('description')->nullable();
                $table->longText('system_prompt');
                $table->unsignedInteger('sort_order')->default(100);
                $table->boolean('is_active')->default(true);
                $table->string('response_format')->default('json');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('wb_api_usage_stats')) {
            Schema::create('wb_api_usage_stats', function (Blueprint $table) {
                $table->id();
                $table->date('stat_date');
                $table->text('api_key')->nullable();
                $table->string('api_key_hash')->nullable();
                $table->unsignedInteger('requests_count')->default(0);
                $table->string('legal_entity')->nullable();
                $table->string('seller_id')->nullable();
                $table->timestamp('legal_entity_synced_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('wb_api_request_logs')) {
            Schema::create('wb_api_request_logs', function (Blueprint $table) {
                $table->id();
                $table->string('api_key')->nullable();
                $table->string('api_key_hash')->nullable();
                $table->string('method')->nullable();
                $table->string('endpoint')->nullable();
                $table->json('request_data')->nullable();
                $table->integer('response_code')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }
    }
}