<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $permissionNames = [
        'subscriber wb advert',
        'subscriber wb autosupply',
        'admin wb adverts controll',
    ];

    public function up(): void
    {
        $permissionIds = DB::table('permissions')
            ->where('guard_name', 'api')
            ->whereIn('name', $this->permissionNames)
            ->pluck('id');

        if ($permissionIds->isEmpty()) {
            return;
        }

        DB::table('model_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('role_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }

    public function down(): void
    {
        // Permissions are re-created only via Roles seeder on fresh installs.
    }
};