<?php

namespace Tests\Feature\Web\Admin;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Web\Auth\WebAuthTestCase;

class AdminSubscriberTest extends WebAuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setupAdminSchema();

        Role::firstOrCreate([
            'name' => 'super-admin',
            'guard_name' => 'web',
        ]);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function test_subscribers_page_requires_super_admin(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('password'),
        ]);

        $this->actingAs($user)
            ->get('/cw-page/subscribers')
            ->assertForbidden();
    }

    public function test_super_admin_can_open_subscribers_page(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('password'),
        ]);

        $user->assignRole('super-admin');

        $this->actingAs($user)
            ->get('/cw-page/subscribers')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Subscribers/Index'));
    }

    public function test_super_admin_can_open_plans_and_payments_pages(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('password'),
        ]);

        $user->assignRole('super-admin');

        $this->actingAs($user)
            ->get('/cw-page/plans')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Plans/Index'));

        $this->actingAs($user)
            ->get('/cw-page/payments')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Payments/Index'));
    }

    private function setupAdminSchema(): void
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

        if (! Schema::hasTable('extra_limits')) {
            Schema::create('extra_limits', function (Blueprint $table) {
                $table->id();
                $table->string('limit_name');
                $table->unsignedInteger('quantity')->default(0);
                $table->decimal('price', 10, 2)->default(0);
                $table->unsignedInteger('order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('payments_transactions')) {
            Schema::create('payments_transactions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->decimal('amount', 12, 2)->default(0);
                $table->string('description')->nullable();
                $table->string('system')->nullable();
                $table->string('system_id')->nullable();
                $table->string('status')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_costs')) {
            Schema::create('ai_costs', function (Blueprint $table) {
                $table->id();
                $table->string('provider')->nullable();
                $table->decimal('cost', 12, 6)->default(0);
                $table->date('date')->nullable();
                $table->timestamps();
            });
        }
    }
}