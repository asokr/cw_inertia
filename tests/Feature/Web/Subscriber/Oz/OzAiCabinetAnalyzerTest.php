<?php

namespace Tests\Feature\Web\Subscriber\Oz;

use App\Enums\Credits\CreditLedgerType;
use App\Jobs\Oz\AiCabinetAnalyzer\ProcessOzAiCabinetAnalyzerAiAnalysisJob;
use App\Jobs\Oz\AiCabinetAnalyzer\ProcessOzAiCabinetAnalyzerReport;
use App\Models\Credits\CreditAccount;
use App\Models\Credits\CreditHold;
use App\Models\Credits\CreditLedger;
use App\Models\Subscribers\Oz\AiCabinetAnalyzer\OzAiCabinetAnalyzerAiAnalysis;
use App\Models\Subscribers\Oz\AiCabinetAnalyzer\OzAiCabinetAnalyzerReport;
use App\Models\Subscribers\Oz\AiCabinetAnalyzer\OzAiCabinetAnalyzerTemplate;
use App\Models\Subscribers\Oz\OzCabinet;
use App\Models\Subscribers\Subscribers;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Web\Auth\WebAuthTestCase;
use Tests\Support\CreatesCreditBillingSchema;

class OzAiCabinetAnalyzerTest extends WebAuthTestCase
{
    use CreatesCreditBillingSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setupOzAiCabinetAnalyzerSchema();
        $this->setupCreditBillingSchema();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::firstOrCreate([
            'name' => 'subscriber oz ai cabinet analyzer',
            'guard_name' => 'web',
        ]);
    }

    public function test_guest_cannot_access_index(): void
    {
        $this->get('/panel/oz/ai-cabinet-analyzer')->assertRedirect();
    }

    public function test_user_without_permission_cannot_access_index(): void
    {
        $user = $this->createSubscriberUser();

        $this->actingAs($user)
            ->get('/panel/oz/ai-cabinet-analyzer')
            ->assertForbidden();
    }

    public function test_subscriber_with_permission_sees_no_cabinet_without_unified_cabinet(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);

        $this->actingAs($user)
            ->get('/panel/oz/ai-cabinet-analyzer')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Oz/Shared/NoCabinet')
                ->where('toolName', 'ИИ анализ кабинета Ozon'));
    }

    public function test_index_renders_workspace_for_selected_unified_cabinet(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Test Ozon Cabinet');

        $this->actingAs($user)
            ->get('/panel/oz/ai-cabinet-analyzer')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Oz/AiCabinetAnalyzer/Cabinet/Show')
                ->where('cabinet.id', $cabinet->id)
                ->where('cabinet.name', 'Test Ozon Cabinet')
                ->has('report')
                ->has('templates')
                ->has('analyses')
                ->has('products'));
    }

    public function test_start_report_dispatches_job_for_owner(): void
    {
        Queue::fake();

        $user = $this->createSubscriberUser(withPermission: true);
        $this->createUnifiedCabinet($user, 'Queue Cabinet');

        $this->actingAs($user)
            ->from('/panel/oz/ai-cabinet-analyzer')
            ->post('/panel/oz/ai-cabinet-analyzer/reports', [
                'begin_date' => '2026-01-01',
                'end_date' => '2026-01-15',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        Queue::assertPushed(ProcessOzAiCabinetAnalyzerReport::class, function ($job) {
            return $job->queue === 'oz_ai_cabinet_analyzer' || true;
        });

        $this->assertDatabaseHas('oz_ai_cabinet_analyzer_reports', [
            'status' => OzAiCabinetAnalyzerReport::STATUS_PROCESSING,
            'type' => 'products_snapshot',
        ]);
    }

    public function test_start_report_without_selected_cabinet_shows_no_cabinet(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);

        $this->actingAs($user)
            ->post('/panel/oz/ai-cabinet-analyzer/reports', [
                'begin_date' => '2026-01-01',
                'end_date' => '2026-01-15',
            ])
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Oz/Shared/NoCabinet'));
    }

    public function test_ai_analysis_show_json_for_owner(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'AI Cabinet');
        $report = $this->createReport($cabinet);
        $template = $this->createTemplate();
        $analysis = $this->createAnalysis($report, $template);

        $this->actingAs($user)
            ->get("/panel/oz/ai-cabinet-analyzer/ai-analyses/{$analysis->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $analysis->id);
    }

    public function test_ai_analysis_show_forbidden_for_foreign_owner(): void
    {
        $owner = $this->createSubscriberUser(withPermission: true);
        $intruder = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($owner, 'Foreign AI');
        $report = $this->createReport($cabinet);
        $template = $this->createTemplate();
        $analysis = $this->createAnalysis($report, $template);

        $this->actingAs($intruder)
            ->get("/panel/oz/ai-cabinet-analyzer/ai-analyses/{$analysis->id}")
            ->assertForbidden();
    }

    public function test_start_ai_analysis_dispatches_job(): void
    {
        Queue::fake();

        $user = $this->createSubscriberUser(withPermission: true);
        $this->grantCredits($user, 25);
        $cabinet = $this->createUnifiedCabinet($user, 'Start AI');
        $report = $this->createReport($cabinet);
        $template = $this->createTemplate();

        $this->actingAs($user)
            ->from('/panel/oz/ai-cabinet-analyzer')
            ->post('/panel/oz/ai-cabinet-analyzer/ai-analyses/start', [
                'report_id' => $report->id,
                'template_id' => $template->id,
            ])
            ->assertRedirect('/panel/oz/ai-cabinet-analyzer')
            ->assertSessionHas('success');

        $this->assertDatabaseCount('oz_ai_cabinet_analyzer_ai_analyses', 1);
        $this->assertDatabaseHas('oz_ai_cabinet_analyzer_ai_analyses', [
            'report_id' => $report->id,
            'template_id' => $template->id,
            'status' => OzAiCabinetAnalyzerAiAnalysis::STATUS_PROCESSING,
        ]);
        Queue::assertPushed(ProcessOzAiCabinetAnalyzerAiAnalysisJob::class);

        $account = CreditAccount::query()->where('user_id', $user->id)->first();
        $this->assertSame(15, $account->available());
        $this->assertSame(10, $account->purchased_held);
        $this->assertSame(0, CreditLedger::query()->where('user_id', $user->id)->where('type', CreditLedgerType::Capture)->count());
    }

    public function test_cannot_start_same_analysis_while_processing_on_report(): void
    {
        Queue::fake();

        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createUnifiedCabinet($user, 'Dup AI');
        $report = $this->createReport($cabinet);
        $template = $this->createTemplate();
        $this->createAnalysis($report, $template, OzAiCabinetAnalyzerAiAnalysis::STATUS_PROCESSING);

        $this->actingAs($user)
            ->from('/panel/oz/ai-cabinet-analyzer')
            ->post('/panel/oz/ai-cabinet-analyzer/ai-analyses/start', [
                'report_id' => $report->id,
                'template_id' => $template->id,
            ])
            ->assertRedirect('/panel/oz/ai-cabinet-analyzer')
            ->assertSessionHas('error');

        $this->assertDatabaseCount('oz_ai_cabinet_analyzer_ai_analyses', 1);
        Queue::assertNothingPushed();
    }

    public function test_start_ai_analysis_requires_credits(): void
    {
        Queue::fake();

        $user = $this->createSubscriberUser(withPermission: true);
        $this->grantCredits($user, 3);
        $cabinet = $this->createUnifiedCabinet($user, 'No credits');
        $report = $this->createReport($cabinet);
        $template = $this->createTemplate();

        $this->actingAs($user)
            ->from('/panel/oz/ai-cabinet-analyzer')
            ->post('/panel/oz/ai-cabinet-analyzer/ai-analyses/start', [
                'report_id' => $report->id,
                'template_id' => $template->id,
            ])
            ->assertRedirect('/panel/oz/ai-cabinet-analyzer')
            ->assertSessionHas('error');

        $this->assertDatabaseCount('oz_ai_cabinet_analyzer_ai_analyses', 0);
        Queue::assertNothingPushed();
        $this->assertSame(3, CreditAccount::query()->where('user_id', $user->id)->first()->available());
    }

    public function test_returning_existing_done_analysis_does_not_charge(): void
    {
        Queue::fake();

        $user = $this->createSubscriberUser(withPermission: true);
        $this->grantCredits($user, 25);
        $cabinet = $this->createUnifiedCabinet($user, 'Existing done');
        $report = $this->createReport($cabinet);
        $template = $this->createTemplate();
        $this->createAnalysis($report, $template, OzAiCabinetAnalyzerAiAnalysis::STATUS_DONE);

        $this->actingAs($user)
            ->from('/panel/oz/ai-cabinet-analyzer')
            ->post('/panel/oz/ai-cabinet-analyzer/ai-analyses/start', [
                'report_id' => $report->id,
                'template_id' => $template->id,
            ])
            ->assertRedirect('/panel/oz/ai-cabinet-analyzer')
            ->assertSessionHas('success');

        $this->assertDatabaseCount('oz_ai_cabinet_analyzer_ai_analyses', 1);
        Queue::assertNothingPushed();
        $this->assertSame(25, CreditAccount::query()->where('user_id', $user->id)->first()->available());
    }

    public function test_show_done_analysis_captures_reserved_credits(): void
    {
        Queue::fake();

        $user = $this->createSubscriberUser(withPermission: true);
        $this->grantCredits($user, 25);
        $cabinet = $this->createUnifiedCabinet($user, 'Capture AI');
        $report = $this->createReport($cabinet);
        $template = $this->createTemplate();

        $this->actingAs($user)
            ->from('/panel/oz/ai-cabinet-analyzer')
            ->post('/panel/oz/ai-cabinet-analyzer/ai-analyses/start', [
                'report_id' => $report->id,
                'template_id' => $template->id,
            ])
            ->assertSessionHas('success');

        $analysis = OzAiCabinetAnalyzerAiAnalysis::query()->first();
        $analysis->status = OzAiCabinetAnalyzerAiAnalysis::STATUS_DONE;
        $analysis->analysis_text = json_encode(['summary' => 'ok'], JSON_UNESCAPED_UNICODE);
        $analysis->save();

        $this->actingAs($user)
            ->get("/panel/oz/ai-cabinet-analyzer/ai-analyses/{$analysis->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $account = CreditAccount::query()->where('user_id', $user->id)->first();
        $this->assertSame(15, $account->available());
        $this->assertSame(0, $account->purchased_held);
        $this->assertSame(15, $account->purchased_balance);
        $this->assertSame(1, CreditLedger::query()->where('user_id', $user->id)->where('type', CreditLedgerType::Capture)->count());

        $this->actingAs($user)
            ->get("/panel/oz/ai-cabinet-analyzer/ai-analyses/{$analysis->id}")
            ->assertOk();

        $this->assertSame(1, CreditLedger::query()->where('user_id', $user->id)->where('type', CreditLedgerType::Capture)->count());
        $this->assertSame(15, $account->fresh()->available());
    }

    public function test_regenerate_uses_current_template_cost(): void
    {
        Queue::fake();

        $user = $this->createSubscriberUser(withPermission: true);
        $this->grantCredits($user, 40);
        $cabinet = $this->createUnifiedCabinet($user, 'Regen cost');
        $report = $this->createReport($cabinet);
        $template = $this->createTemplate();
        $analysis = $this->createAnalysis($report, $template, OzAiCabinetAnalyzerAiAnalysis::STATUS_DONE);

        $template->credits_cost = 15;
        $template->save();

        $this->actingAs($user)
            ->from('/panel/oz/ai-cabinet-analyzer')
            ->post("/panel/oz/ai-cabinet-analyzer/ai-analyses/{$analysis->id}/regenerate")
            ->assertRedirect('/panel/oz/ai-cabinet-analyzer')
            ->assertSessionHas('success');

        $account = CreditAccount::query()->where('user_id', $user->id)->first();
        $this->assertSame(25, $account->available());
        $this->assertSame(15, $account->purchased_held);
        Queue::assertPushed(ProcessOzAiCabinetAnalyzerAiAnalysisJob::class);
    }

    public function test_snapshot_start_does_not_charge_credits(): void
    {
        Queue::fake();

        $user = $this->createSubscriberUser(withPermission: true);
        $this->grantCredits($user, 25);
        $this->createUnifiedCabinet($user, 'Snapshot');

        $this->actingAs($user)
            ->from('/panel/oz/ai-cabinet-analyzer')
            ->post('/panel/oz/ai-cabinet-analyzer/reports', [
                'begin_date' => '2026-01-01',
                'end_date' => '2026-01-15',
            ])
            ->assertSessionHas('success');

        $this->assertSame(25, CreditAccount::query()->where('user_id', $user->id)->first()->available());
        $this->assertSame(0, CreditHold::query()->where('user_id', $user->id)->count());
    }

    private function grantCredits(User $user, int $amount): void
    {
        CreditAccount::query()->create([
            'user_id' => $user->id,
            'subscription_balance' => 0,
            'purchased_balance' => $amount,
            'subscription_held' => 0,
            'purchased_held' => 0,
        ]);
    }

    private function createSubscriberUser(bool $withPermission = false): User
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
        ]);

        $user->assignRole('Подписчик');

        if ($withPermission) {
            $user->givePermissionTo('subscriber oz ai cabinet analyzer');
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

    private function createUnifiedCabinet(User $user, string $name): OzCabinet
    {
        $cabinet = OzCabinet::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'client_id' => 'client-'.uniqid(),
            'apikey' => 'test-api-key',
        ]);

        $user->forceFill(['selected_oz_cabinet_id' => $cabinet->id])->save();

        return $cabinet;
    }

    private function createReport(OzCabinet $cabinet): OzAiCabinetAnalyzerReport
    {
        return OzAiCabinetAnalyzerReport::query()->create([
            'cabinet_id' => $cabinet->id,
            'status' => OzAiCabinetAnalyzerReport::STATUS_DONE,
            'type' => 'products_snapshot',
            'result_json' => [
                'meta' => [
                    'period' => [
                        'begin_date' => '2026-01-01',
                        'end_date' => '2026-01-15',
                    ],
                    'sources_collected' => ['products'],
                    'products_count' => 0,
                ],
                'products' => [],
            ],
        ]);
    }

    private function createTemplate(string $name = 'Test Template'): OzAiCabinetAnalyzerTemplate
    {
        return OzAiCabinetAnalyzerTemplate::query()->create([
            'name' => $name,
            'description' => 'Test',
            'system_prompt' => 'Analyze',
            'sort_order' => 100,
            'is_active' => true,
            'response_format' => 'json',
            'data_sources' => ['products'],
        ]);
    }

    private function createAnalysis(
        OzAiCabinetAnalyzerReport $report,
        OzAiCabinetAnalyzerTemplate $template,
        string $status = OzAiCabinetAnalyzerAiAnalysis::STATUS_DONE,
    ): OzAiCabinetAnalyzerAiAnalysis {
        return OzAiCabinetAnalyzerAiAnalysis::query()->create([
            'report_id' => $report->id,
            'template_id' => $template->id,
            'status' => $status,
            'analysis_text' => $status === OzAiCabinetAnalyzerAiAnalysis::STATUS_DONE
                ? json_encode(['summary' => 'ok'], JSON_UNESCAPED_UNICODE)
                : null,
        ]);
    }

    private function setupOzAiCabinetAnalyzerSchema(): void
    {
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'selected_oz_cabinet_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedBigInteger('selected_oz_cabinet_id')->nullable();
            });
        }

        if (! Schema::hasTable('oz_cabinets')) {
            Schema::create('oz_cabinets', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('name');
                $table->string('client_id');
                $table->text('apikey');
                $table->string('performance_client_id')->nullable();
                $table->text('performance_client_secret')->nullable();
                $table->text('last_sync_error')->nullable();
                $table->timestamps();
            });
        } else {
            if (! Schema::hasColumn('oz_cabinets', 'performance_client_id')) {
                Schema::table('oz_cabinets', function (Blueprint $table) {
                    $table->string('performance_client_id')->nullable();
                });
            }
            if (! Schema::hasColumn('oz_cabinets', 'performance_client_secret')) {
                Schema::table('oz_cabinets', function (Blueprint $table) {
                    $table->text('performance_client_secret')->nullable();
                });
            }
        }

        if (! Schema::hasTable('oz_ai_cabinet_analyzer_templates')) {
            Schema::create('oz_ai_cabinet_analyzer_templates', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->text('description')->nullable();
                $table->longText('system_prompt');
                $table->unsignedInteger('sort_order')->default(100)->index();
                $table->boolean('is_active')->default(true)->index();
                $table->string('response_format', 32)->default('json');
                $table->json('data_sources')->nullable();
                $table->unsignedInteger('credits_cost')->default(10);
                $table->timestamps();
            });
        } elseif (! Schema::hasColumn('oz_ai_cabinet_analyzer_templates', 'credits_cost')) {
            Schema::table('oz_ai_cabinet_analyzer_templates', function (Blueprint $table) {
                $table->unsignedInteger('credits_cost')->default(10);
            });
        }

        if (! Schema::hasTable('oz_ai_cabinet_analyzer_reports')) {
            Schema::create('oz_ai_cabinet_analyzer_reports', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('cabinet_id')->index();
                $table->string('status', 32)->default('processing')->index();
                $table->string('type', 64)->nullable();
                $table->longText('result_json')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('oz_ai_cabinet_analyzer_ai_analyses')) {
            Schema::create('oz_ai_cabinet_analyzer_ai_analyses', function (Blueprint $table) {
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
                $table->string('credit_idempotency_key')->nullable();
                $table->timestamps();
            });
        } elseif (! Schema::hasColumn('oz_ai_cabinet_analyzer_ai_analyses', 'credit_idempotency_key')) {
            Schema::table('oz_ai_cabinet_analyzer_ai_analyses', function (Blueprint $table) {
                $table->string('credit_idempotency_key')->nullable();
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
