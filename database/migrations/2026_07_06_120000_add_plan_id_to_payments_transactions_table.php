<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payments_transactions')) {
            return;
        }

        if (Schema::hasColumn('payments_transactions', 'plan_id')) {
            return;
        }

        Schema::table('payments_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('plan_id')->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('payments_transactions')) {
            return;
        }

        if (! Schema::hasColumn('payments_transactions', 'plan_id')) {
            return;
        }

        Schema::table('payments_transactions', function (Blueprint $table) {
            $table->dropColumn('plan_id');
        });
    }
};