<?php

namespace Tests\Feature\Web\Home;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\Feature\Web\Auth\WebAuthTestCase;

class HomePageTest extends WebAuthTestCase
{
    public function test_subscriber_sees_home_with_panel_link(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);
        $user->assignRole('Подписчик');

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Home/Index')
                ->where('authenticated', true)
                ->where('homeUrl', '/panel')
                ->where('cabinetLabel', 'К инструментам')
                ->where('isSubscriber', true));
    }

    public function test_admin_sees_home_with_admin_link(): void
    {
        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

        $user = User::factory()->create(['password' => Hash::make('password')]);
        $user->assignRole('super-admin');

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Home/Index')
                ->where('authenticated', true)
                ->where('homeUrl', '/cw-page')
                ->where('cabinetLabel', 'В админку'));
    }
}