<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

return new class extends Migration
{
    private const PERMISSION = 'subscriber wb ab testing';

    public function up(): void
    {
        Permission::updateOrCreate([
            'guard_name' => 'web',
            'name' => self::PERMISSION,
        ]);
    }

    public function down(): void
    {
        Permission::query()
            ->where('guard_name', 'web')
            ->where('name', self::PERMISSION)
            ->delete();
    }
};
