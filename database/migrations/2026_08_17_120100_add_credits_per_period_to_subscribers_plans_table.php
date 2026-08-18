<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('subscribers_plans')) {
            return;
        }

        if (! Schema::hasColumn('subscribers_plans', 'credits_per_period')) {
            Schema::table('subscribers_plans', function (Blueprint $table) {
                $table->unsignedInteger('credits_per_period')->default(0)->after('limits_month');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('subscribers_plans')) {
            return;
        }

        if (Schema::hasColumn('subscribers_plans', 'credits_per_period')) {
            Schema::table('subscribers_plans', function (Blueprint $table) {
                $table->dropColumn('credits_per_period');
            });
        }
    }
};
