<?php

namespace Tests\Feature\Web\Subscriber\Wb;

use App\Jobs\Wb\AiCabinetAnalyzer\ProcessAiCabinetAnalyzerReport;
use App\Models\Subscribers\Subscribers;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\Subscribers\Wb\AiCabinetAnalyzer\AiCabinetAnalyzerAiAnalysis;
use App\Models\Subscribers\Wb\AiCabinetAnalyzer\AiCabinetAnalyzerCabinet;
use App\Models\Subscribers\Wb\AiCabinetAnalyzer\AiCabinetAnalyzerReport;
use App\Models\Subscribers\Wb\AiCabinetAnalyzer\AiCabinetAnalyzerTemplate;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Web\Auth\WebAuthTestCase;

class WbAiCabinetAnalyzerTest extends WebAuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setupAiCabinetAnalyzerSchema();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::firstOrCreate([
            'name' => 'subscriber wb ai cabinet analyzer',
            'guard_name' => 'web',
        ]);
    }

    public function test_guest_cannot_access_index(): void
    {
        $this->get('/panel/wb/ai-cabinet-analyzer')->assertUnauthorized();
    }

    public function test_user_without_permission_cannot_access_index(): void
    {
        $user = $this->createSubscriberUser();

        $this->actingAs($user)
            ->get('/panel/wb/ai-cabinet-analyzer')
            ->assertForbidden();
    }

    public function test_subscriber_with_permission_can_access_index(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);

        $this->actingAs($user)
            ->get('/panel/wb/ai-cabinet-analyzer')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Wb/AiCabinetAnalyzer/Index')
                ->has('cabinets'));
    }

    public function test_index_lists_owned_cabinets(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'Test Cabinet');

        $this->actingAs($user)
            ->get('/panel/wb/ai-cabinet-analyzer')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('cabinets.0.name', 'Test Cabinet')
                ->where('cabinets.0.id', $cabinet->id));
    }

    public function test_cabinet_show_renders_for_owner(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'Report Cabinet');

        $this->actingAs($user)
            ->get("/panel/wb/ai-cabinet-analyzer/cabinets/{$cabinet->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Wb/AiCabinetAnalyzer/Cabinet/Show')
                ->where('cabinet.id', $cabinet->id)
                ->has('report')
                ->has('templates')
                ->has('analyses'));
    }

    public function test_cabinet_show_forbidden_for_foreign_cabinet(): void
    {
        $owner = $this->createSubscriberUser(withPermission: true);
        $intruder = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($owner, 'Foreign');

        $this->actingAs($intruder)
            ->get("/panel/wb/ai-cabinet-analyzer/cabinets/{$cabinet->id}")
            ->assertForbidden();
    }

    public function test_destroy_cabinet_redirects_with_success(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'Delete Me');

        $this->actingAs($user)
            ->delete("/panel/wb/ai-cabinet-analyzer/cabinets/{$cabinet->id}")
            ->assertRedirect('/panel/wb/ai-cabinet-analyzer')
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('wb_ai_cabinet_analyzer_cabinets', ['id' => $cabinet->id]);
    }

    public function test_start_report_dispatches_job_for_owner(): void
    {
        Queue::fake();

        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'Queue Cabinet');

        $this->actingAs($user)
            ->post("/panel/wb/ai-cabinet-analyzer/cabinets/{$cabinet->id}/reports", [
                'begin_date' => '2026-01-01',
                'end_date' => '2026-01-15',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        Queue::assertPushed(ProcessAiCabinetAnalyzerReport::class);
    }

    public function test_start_report_forbidden_for_foreign_cabinet(): void
    {
        $owner = $this->createSubscriberUser(withPermission: true);
        $intruder = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($owner, 'Foreign Report');

        $this->actingAs($intruder)
            ->post("/panel/wb/ai-cabinet-analyzer/cabinets/{$cabinet->id}/reports", [
                'begin_date' => '2026-01-01',
                'end_date' => '2026-01-15',
            ])
            ->assertForbidden();
    }

    public function test_ai_analysis_show_json_for_owner(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'AI Cabinet');
        $report = $this->createReport($cabinet);
        $template = $this->createTemplate();
        $analysis = $this->createAnalysis($report, $template);

        $this->actingAs($user)
            ->get("/panel/wb/ai-cabinet-analyzer/ai-analyses/{$analysis->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $analysis->id);
    }

    public function test_ai_analysis_show_forbidden_for_foreign_owner(): void
    {
        $owner = $this->createSubscriberUser(withPermission: true);
        $intruder = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($owner, 'Foreign AI');
        $report = $this->createReport($cabinet);
        $template = $this->createTemplate();
        $analysis = $this->createAnalysis($report, $template);

        $this->actingAs($intruder)
            ->get("/panel/wb/ai-cabinet-analyzer/ai-analyses/{$analysis->id}")
            ->assertForbidden();
    }

    private function createSubscriberUser(bool $withPermission = false): User
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
        ]);

        $user->assignRole('Подписчик');

        if ($withPermission) {
            $user->givePermissionTo('subscriber wb ai cabinet analyzer');
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
        ]);

        return $user;
    }

    private function createCabinet(User $user, string $name): AiCabinetAnalyzerCabinet
    {
        return AiCabinetAnalyzerCabinet::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'apikey' => 'test-api-key',
        ]);
    }

    private function createReport(AiCabinetAnalyzerCabinet $cabinet): AiCabinetAnalyzerReport
    {
        return AiCabinetAnalyzerReport::query()->create([
            'cabinet_id' => $cabinet->id,
            'status' => AiCabinetAnalyzerReport::STATUS_DONE,
            'type' => 'snapshot',
            'result_json' => [
                'meta' => [
                    'period' => [
                        'begin_date' => '2026-01-01',
                        'end_date' => '2026-01-15',
                    ],
                ],
            ],
        ]);
    }

    private function createTemplate(): AiCabinetAnalyzerTemplate
    {
        return AiCabinetAnalyzerTemplate::query()->create([
            'name' => 'Test Template',
            'description' => 'Test',
            'system_prompt' => 'Analyze',
            'sort_order' => 100,
            'is_active' => true,
            'response_format' => 'json',
        ]);
    }

    private function createAnalysis(
        AiCabinetAnalyzerReport $report,
        AiCabinetAnalyzerTemplate $template,
    ): AiCabinetAnalyzerAiAnalysis {
        return AiCabinetAnalyzerAiAnalysis::query()->create([
            'report_id' => $report->id,
            'template_id' => $template->id,
            'status' => AiCabinetAnalyzerAiAnalysis::STATUS_DONE,
            'analysis_text' => json_encode(['summary' => 'ok'], JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function setupAiCabinetAnalyzerSchema(): void
    {
        if (! Schema::hasTable('wb_ai_cabinet_analyzer_cabinets')) {
            Schema::create('wb_ai_cabinet_analyzer_cabinets', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('name');
                $table->text('apikey')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('wb_ai_cabinet_analyzer_reports')) {
            Schema::create('wb_ai_cabinet_analyzer_reports', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('cabinet_id')->index();
                $table->string('status', 32)->default('processing')->index();
                $table->string('type', 64)->nullable();
                $table->json('result_json')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('wb_ai_cabinet_analyzer_templates')) {
            Schema::create('wb_ai_cabinet_analyzer_templates', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->text('description')->nullable();
                $table->longText('system_prompt');
                $table->unsignedInteger('sort_order')->default(100)->index();
                $table->boolean('is_active')->default(true)->index();
                $table->string('response_format', 32)->default('json');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('wb_ai_cabinet_analyzer_ai_analyses')) {
            Schema::create('wb_ai_cabinet_analyzer_ai_analyses', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('report_id')->index();
                $table->unsignedBigInteger('template_id')->index();
                $table->string('status', 32)->default('processing')->index();
                $table->string('model', 120)->nullable();
                $table->longText('analysis_json')->nullable();
                $table->longText('analysis_text')->nullable();
                $table->longText('analysis_markdown')->nullable();
                $table->unsignedInteger('input_tokens')->default(0);
                $table->unsignedInteger('output_tokens')->default(0);
                $table->unsignedInteger('total_tokens')->default(0);
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamps();
            });
        }

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

            DB::table('subscribers_plans')->insert([
                'id' => 1,
                'name' => 'Test Plan',
                'price' => 0,
                'duration' => 30,
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
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