<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prod may have wb_ab_products from 2026_07_25 (without rating_updated_at).
 * 2026_08_05 only creates the table when missing, so the column never appears.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wb_ab_products')) {
            return;
        }

        if (! Schema::hasColumn('wb_ab_products', 'rating_updated_at')) {
            Schema::table('wb_ab_products', function (Blueprint $table) {
                $table->timestamp('rating_updated_at')->nullable()->after('rating');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('wb_ab_products')) {
            return;
        }

        if (Schema::hasColumn('wb_ab_products', 'rating_updated_at')) {
            Schema::table('wb_ab_products', function (Blueprint $table) {
                $table->dropColumn('rating_updated_at');
            });
        }
    }
};
