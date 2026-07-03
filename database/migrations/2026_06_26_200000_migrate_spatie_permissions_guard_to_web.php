<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        DB::table('permissions')
            ->where('guard_name', 'api')
            ->update(['guard_name' => 'web']);

        if (Schema::hasTable('roles')) {
            DB::table('roles')
                ->where('guard_name', 'api')
                ->update(['guard_name' => 'web']);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        DB::table('permissions')
            ->where('guard_name', 'web')
            ->update(['guard_name' => 'api']);

        if (Schema::hasTable('roles')) {
            DB::table('roles')
                ->where('guard_name', 'web')
                ->update(['guard_name' => 'api']);
        }
    }
};