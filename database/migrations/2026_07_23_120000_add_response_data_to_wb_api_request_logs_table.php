<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wb_api_request_logs')) {
            return;
        }

        if (Schema::hasColumn('wb_api_request_logs', 'response_data')) {
            return;
        }

        Schema::table('wb_api_request_logs', function (Blueprint $table) {
            $table->json('response_data')->nullable()->after('request_data');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('wb_api_request_logs')) {
            return;
        }

        if (! Schema::hasColumn('wb_api_request_logs', 'response_data')) {
            return;
        }

        Schema::table('wb_api_request_logs', function (Blueprint $table) {
            $table->dropColumn('response_data');
        });
    }
};
