<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users') || Schema::hasColumn('users', 'selected_oz_cabinet_id')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('selected_oz_cabinet_id')->nullable()->after('selected_wb_cabinet_id');
            $table->index('selected_oz_cabinet_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'selected_oz_cabinet_id')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('selected_oz_cabinet_id');
        });
    }
};
