<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * История остатков Ozon: настройки отслеживания, снимки, товары, склады, факты.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('oz_stock_history_settings')) {
            Schema::create('oz_stock_history_settings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('cabinet_id')
                    ->constrained('oz_cabinets')
                    ->cascadeOnDelete();
                $table->unsignedTinyInteger('retention_days')->default(90);
                $table->boolean('tracking_enabled')->default(false);
                $table->string('tracking_status', 32)->default('idle');
                $table->timestamp('products_synced_at')->nullable();
                $table->unsignedInteger('products_count')->default(0);
                $table->text('last_error')->nullable();
                $table->timestamps();

                $table->unique('cabinet_id');
            });
        }

        if (! Schema::hasTable('oz_stock_history_snapshots')) {
            Schema::create('oz_stock_history_snapshots', function (Blueprint $table) {
                $table->id();
                $table->foreignId('cabinet_id')
                    ->constrained('oz_cabinets')
                    ->cascadeOnDelete();
                $table->date('stock_date');
                $table->string('status', 32)->default('pending');
                $table->timestamp('collected_at')->nullable();
                $table->unsignedInteger('products_count')->default(0);
                $table->unsignedInteger('rows_count')->default(0);
                $table->text('error_message')->nullable();
                $table->timestamps();

                $table->unique(['cabinet_id', 'stock_date']);
                $table->index(['cabinet_id', 'status']);
            });
        }

        if (! Schema::hasTable('oz_stock_history_products')) {
            Schema::create('oz_stock_history_products', function (Blueprint $table) {
                $table->id();
                $table->foreignId('cabinet_id')
                    ->constrained('oz_cabinets')
                    ->cascadeOnDelete();
                $table->unsignedBigInteger('sku');
                $table->unsignedBigInteger('product_id')->nullable();
                $table->string('offer_id')->nullable();
                $table->string('name')->nullable();
                $table->string('image_url', 1024)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['cabinet_id', 'sku']);
                $table->index(['cabinet_id', 'offer_id']);
                $table->index(['cabinet_id', 'name']);
                $table->index(['cabinet_id', 'is_active']);
            });
        }

        if (! Schema::hasTable('oz_stock_history_warehouses')) {
            Schema::create('oz_stock_history_warehouses', function (Blueprint $table) {
                $table->id();
                $table->foreignId('cabinet_id')
                    ->constrained('oz_cabinets')
                    ->cascadeOnDelete();
                $table->string('warehouse_key', 64);
                $table->unsignedBigInteger('warehouse_id')->nullable();
                $table->string('warehouse_name');
                $table->unsignedBigInteger('cluster_id')->nullable();
                $table->string('cluster_name')->nullable();
                $table->timestamps();

                $table->unique(['cabinet_id', 'warehouse_key']);
                $table->index(['cabinet_id', 'cluster_name']);
            });
        }

        if (! Schema::hasTable('oz_stock_history_items')) {
            Schema::create('oz_stock_history_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('cabinet_id')
                    ->constrained('oz_cabinets')
                    ->cascadeOnDelete();
                $table->unsignedBigInteger('sku');
                $table->string('warehouse_key', 64);
                $table->date('stock_date');
                $table->unsignedInteger('qty')->default(0);
                $table->timestamps();

                $table->unique(['cabinet_id', 'sku', 'warehouse_key', 'stock_date'], 'oz_stock_hist_items_unique');
                $table->index(['cabinet_id', 'stock_date'], 'oz_stock_hist_items_date_idx');
                $table->index(['cabinet_id', 'sku', 'stock_date'], 'oz_stock_hist_items_sku_date_idx');
                $table->index(['cabinet_id', 'sku', 'warehouse_key'], 'oz_stock_hist_items_pair_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('oz_stock_history_items');
        Schema::dropIfExists('oz_stock_history_warehouses');
        Schema::dropIfExists('oz_stock_history_products');
        Schema::dropIfExists('oz_stock_history_snapshots');
        Schema::dropIfExists('oz_stock_history_settings');
    }
};
