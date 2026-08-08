<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('oz_cabinets')) {
            return;
        }

        Schema::table('oz_cabinets', function (Blueprint $table) {
            if (! Schema::hasColumn('oz_cabinets', 'performance_client_id')) {
                $table->string('performance_client_id')->nullable()->after('apikey');
            }
            if (! Schema::hasColumn('oz_cabinets', 'performance_client_secret')) {
                $table->text('performance_client_secret')->nullable()->after('performance_client_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('oz_cabinets')) {
            return;
        }

        Schema::table('oz_cabinets', function (Blueprint $table) {
            if (Schema::hasColumn('oz_cabinets', 'performance_client_secret')) {
                $table->dropColumn('performance_client_secret');
            }
            if (Schema::hasColumn('oz_cabinets', 'performance_client_id')) {
                $table->dropColumn('performance_client_id');
            }
        });
    }
};
