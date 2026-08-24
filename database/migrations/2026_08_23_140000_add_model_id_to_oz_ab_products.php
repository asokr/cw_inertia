<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Идентификатор модели Ozon — чтобы в списке A/B сгруппировать размеры одной карточки.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('oz_ab_products') || Schema::hasColumn('oz_ab_products', 'model_id')) {
            return;
        }

        Schema::table('oz_ab_products', function (Blueprint $table) {
            $table->unsignedBigInteger('model_id')->nullable()->after('sku');
            $table->index(['cabinet_id', 'model_id']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('oz_ab_products') || ! Schema::hasColumn('oz_ab_products', 'model_id')) {
            return;
        }

        Schema::table('oz_ab_products', function (Blueprint $table) {
            $table->dropIndex(['cabinet_id', 'model_id']);
            $table->dropColumn('model_id');
        });
    }
};
