<?php

namespace Tests\Feature\Web\Admin;

use App\Models\Subscribers\SubscribersPlans;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Web\Auth\WebAuthTestCase;

class AdminPlanTest extends WebAuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setupPlanSchema();

        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

        Permission::firstOrCreate(['name' => 'subscriber wb feedbacks', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'subscriber ai', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'blog.view', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'administrator', 'guard_name' => 'web']);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function test_plan_form_lists_only_subscriber_permissions(): void
    {
        $user = $this->makeSuperAdmin();

        $this->actingAs($user)
            ->get('/cw-page/plans/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Plans/Form')
                ->has('permissions', 2)
                ->where('permissions.0.name', 'subscriber ai')
                ->where('permissions.1.name', 'subscriber wb feedbacks'));
    }

    public function test_plan_update_rejects_non_subscriber_permissions(): void
    {
        $user = $this->makeSuperAdmin();

        $plan = SubscribersPlans::create([
            'name' => 'Базовый',
            'price' => 1000,
            'duration' => 30,
            'description' => '',
            'limits_plan' => [],
            'limits_month' => [],
            'permissions' => ['subscriber wb feedbacks'],
            'status' => 1,
            'hidden' => 0,
        ]);

        $this->actingAs($user)
            ->put("/cw-page/plans/{$plan->id}", [
                'name' => 'Базовый',
                'price' => 1000,
                'duration' => 30,
                'description' => '',
                'limits_plan' => '',
                'limits_month' => '',
                'permissions' => ['blog.view'],
                'status' => 1,
                'hidden' => 0,
            ])
            ->assertSessionHasErrors('permissions.0');
    }

    public function test_plan_update_accepts_subscriber_permissions(): void
    {
        $user = $this->makeSuperAdmin();

        $plan = SubscribersPlans::create([
            'name' => 'Базовый',
            'price' => 1000,
            'duration' => 30,
            'description' => '',
            'limits_plan' => [],
            'limits_month' => [],
            'permissions' => ['subscriber wb feedbacks'],
            'status' => 1,
            'hidden' => 0,
        ]);

        $this->actingAs($user)
            ->put("/cw-page/plans/{$plan->id}", [
                'name' => 'Оптимальный',
                'price' => 2000,
                'duration' => 30,
                'description' => '',
                'limits_plan' => '',
                'limits_month' => '',
                'permissions' => ['subscriber ai', 'subscriber wb feedbacks'],
                'status' => 1,
                'hidden' => 0,
            ])
            ->assertRedirect(route('admin.plans.index'));

        $plan->refresh();

        $this->assertSame('Оптимальный', $plan->name);
        $this->assertSame(['subscriber ai', 'subscriber wb feedbacks'], $plan->permissions);
    }

    private function makeSuperAdmin(): User
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);
        $user->assignRole('super-admin');

        return $user;
    }

    private function setupPlanSchema(): void
    {
        if (! Schema::hasTable('subscribers_plans')) {
            Schema::create('subscribers_plans', function (Blueprint $table) {
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
            });
        }

        if (! Schema::hasTable('subscribers_subscriptions')) {
            Schema::create('subscribers_subscriptions', function (Blueprint $table) {
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
            });
        }
    }
}