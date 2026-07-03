<?php

namespace Tests\Feature\Web\Subscriber\Oz;

use App\Models\Subscribers\Oz\Feedbacks\FeedbacksClients;
use App\Models\Subscribers\Subscribers;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Web\Auth\WebAuthTestCase;

class OzFeedbacksTest extends WebAuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setupFeedbacksSchema();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::firstOrCreate([
            'name' => 'subscriber oz feedbacks',
            'guard_name' => 'web',
        ]);
    }

    public function test_guest_cannot_access_oz_feedbacks_index(): void
    {
        $this->get('/panel/oz/feedbacks')->assertUnauthorized();
    }

    public function test_user_without_permission_cannot_access_oz_feedbacks_index(): void
    {
        $user = $this->createSubscriberUser();

        $this->actingAs($user)
            ->get('/panel/oz/feedbacks')
            ->assertForbidden();
    }

    public function test_subscriber_with_permission_can_access_index(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);

        $this->actingAs($user)
            ->get('/panel/oz/feedbacks')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Oz/Feedbacks/Index')
                ->has('cabinets'));
    }

    public function test_index_lists_owned_cabinets(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'Test Cabinet');

        $this->actingAs($user)
            ->get('/panel/oz/feedbacks')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('cabinets.0.name', 'Test Cabinet')
                ->where('cabinets.0.id', $cabinet->id));
    }

    public function test_cabinet_show_renders_for_owner(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'Client Cabinet');

        $this->actingAs($user)
            ->get("/panel/oz/feedbacks/cabinets/{$cabinet->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriber/Oz/Feedbacks/Cabinet/Show')
                ->where('cabinet.id', $cabinet->id)
                ->has('reviews'));
    }

    public function test_cabinet_show_forbidden_for_foreign_cabinet(): void
    {
        $owner = $this->createSubscriberUser(withPermission: true);
        $intruder = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($owner, 'Foreign');

        $this->actingAs($intruder)
            ->get("/panel/oz/feedbacks/cabinets/{$cabinet->id}")
            ->assertForbidden();
    }

    public function test_destroy_cabinet_redirects_with_success(): void
    {
        $user = $this->createSubscriberUser(withPermission: true);
        $cabinet = $this->createCabinet($user, 'Delete Me');

        $this->actingAs($user)
            ->delete("/panel/oz/feedbacks/cabinets/{$cabinet->id}")
            ->assertRedirect('/panel/oz/feedbacks')
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('oz_feedbacks_clients', ['id' => $cabinet->id]);
    }

    private function createSubscriberUser(bool $withPermission = false): User
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
        ]);

        $user->assignRole('Подписчик');

        if ($withPermission) {
            $user->givePermissionTo('subscriber oz feedbacks');
        }

        Subscribers::query()->create([
            'user_id' => $user->id,
            'status' => 1,
        ]);

        return $user;
    }

    private function createCabinet(User $user, string $name): FeedbacksClients
    {
        return FeedbacksClients::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'client_id' => '12345',
            'apikey' => 'test-api-key',
            'bot_status' => 0,
            'ai_status' => 0,
            'empty_answer' => 0,
        ]);
    }

    private function setupFeedbacksSchema(): void
    {
        if (! Schema::hasTable('oz_feedbacks_clients')) {
            Schema::create('oz_feedbacks_clients', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('name');
                $table->text('apikey')->nullable();
                $table->string('client_id')->nullable();
                $table->unsignedTinyInteger('bot_status')->default(0);
                $table->unsignedTinyInteger('ai_status')->default(0);
                $table->json('ai_ratings')->nullable();
                $table->string('signature')->nullable();
                $table->unsignedTinyInteger('empty_answer')->default(0);
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