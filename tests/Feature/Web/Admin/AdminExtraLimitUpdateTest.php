<?php

namespace Tests\Feature\Web\Admin;

use App\Models\ExtraLimits;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Web\Auth\WebAuthTestCase;

class AdminExtraLimitUpdateTest extends WebAuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('extra_limits')) {
            Schema::create('extra_limits', function (Blueprint $table) {
                $table->id();
                $table->string('slug')->unique();
                $table->string('name');
                $table->decimal('price', 12, 4)->default(0);
                $table->unsignedInteger('order')->default(0);
                $table->timestamps();
            });
        }

        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function test_super_admin_can_update_extra_limit(): void
    {
        $admin = User::factory()->create([
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
        ]);
        $admin->assignRole('super-admin');

        $limit = ExtraLimits::query()->create([
            'slug' => 'ai_text_query',
            'name' => 'Текстовые запросы',
            'price' => 1,
            'order' => 1,
        ]);

        $this->actingAs($admin)
            ->put("/cw-page/extra-limits/{$limit->id}", [
                'slug' => 'ai_text_query',
                'name' => 'Текстовые запросы к ИИ',
                'price' => 2.5,
                'order' => 5,
            ])
            ->assertRedirect(route('admin.extra-limits.index'))
            ->assertSessionHas('success');

        $limit->refresh();
        $this->assertSame('Текстовые запросы к ИИ', $limit->name);
        $this->assertEqualsWithDelta(2.5, (float) $limit->price, 0.001);
        $this->assertSame(5, (int) $limit->order);
    }

    public function test_update_unknown_id_returns_404(): void
    {
        $admin = User::factory()->create([
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
        ]);
        $admin->assignRole('super-admin');

        $this->actingAs($admin)
            ->put('/cw-page/extra-limits/999999', [
                'slug' => 'x',
                'name' => 'X',
                'price' => 1,
                'order' => 1,
            ])
            ->assertNotFound();
    }
}
