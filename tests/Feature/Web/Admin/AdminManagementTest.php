<?php

namespace Tests\Feature\Web\Admin;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Web\Auth\WebAuthTestCase;

class AdminManagementTest extends WebAuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setupManagementSchema();

        Role::firstOrCreate([
            'name' => 'super-admin',
            'guard_name' => 'web',
        ]);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function test_management_pages_require_super_admin(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('password'),
        ]);

        $this->actingAs($user)->get('/cw-page/coupons')->assertForbidden();
        $this->actingAs($user)->get('/cw-page/users')->assertForbidden();
        $this->actingAs($user)->get('/cw-page/roles')->assertForbidden();
        $this->actingAs($user)->get('/cw-page/sent-emails')->assertForbidden();
    }

    public function test_super_admin_can_open_management_pages(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('password'),
        ]);

        $user->assignRole('super-admin');

        $this->actingAs($user)
            ->get('/cw-page/coupons')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Coupons/Index'));

        $this->actingAs($user)
            ->get('/cw-page/users')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Users/Index'));

        $this->actingAs($user)
            ->get('/cw-page/roles')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Roles/Index'));

        $this->actingAs($user)
            ->get('/cw-page/sent-emails')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/SentEmails/Index'));
    }

    private function setupManagementSchema(): void
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

        if (! Schema::hasTable('coupons')) {
            Schema::create('coupons', function (Blueprint $table) {
                $table->id();
                $table->string('code');
                $table->unsignedInteger('limit')->nullable();
                $table->string('type');
                $table->decimal('value', 12, 2);
                $table->timestamp('start_date')->nullable();
                $table->timestamp('end_date')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('sent_emails')) {
            Schema::create('sent_emails', function (Blueprint $table) {
                $table->id();
                $table->string('to')->nullable();
                $table->string('subject')->nullable();
                $table->longText('body')->nullable();
                $table->string('type')->nullable();
                $table->string('status')->nullable();
                $table->text('error_message')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();
            });
        }
    }
}