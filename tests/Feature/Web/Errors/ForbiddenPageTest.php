<?php

namespace Tests\Feature\Web\Errors;

use App\Models\User;
use App\Support\HomeRedirect;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Web\Auth\WebAuthTestCase;

class ForbiddenPageTest extends WebAuthTestCase
{
    public function test_unprivileged_user_sees_custom_forbidden_page(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
        ]);

        $this->actingAs($user)
            ->get('/panel')
            ->assertForbidden()
            ->assertInertia(fn ($page) => $page
                ->component('Errors/Forbidden')
                ->where('authenticated', true)
                ->where('isSubscriber', false)
                ->where('isAdmin', false)
                ->where('canAccessPanel', false));
    }

    public function test_admin_has_access_to_panel_tools(): void
    {
        Permission::firstOrCreate(['name' => 'blog.view', 'guard_name' => 'web']);

        $user = User::factory()->create([
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
        ]);
        $user->givePermissionTo('blog.view');

        $this->actingAs($user);

        $this->assertTrue(HomeRedirect::canAccessPanel($user));
        $this->assertTrue($user->can('subscriber wb feedbacks'));
        $this->assertTrue($user->can('subscriber ai'));
    }

    public function test_admin_sees_panel_cta_on_forbidden_page(): void
    {
        Permission::firstOrCreate(['name' => 'blog.view', 'guard_name' => 'web']);

        $user = User::factory()->create([
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
        ]);
        $user->givePermissionTo('blog.view');

        $this->actingAs($user)
            ->get('/cw-page/users')
            ->assertForbidden()
            ->assertInertia(fn ($page) => $page
                ->component('Errors/Forbidden')
                ->where('authenticated', true)
                ->where('isAdmin', true)
                ->where('canAccessPanel', true)
                ->where('homeUrl', '/cw-page'));
    }
}