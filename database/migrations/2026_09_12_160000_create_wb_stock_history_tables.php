<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * История остатков и заказов Wildberries.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wb_stock_history_settings')) {
            Schema::create('wb_stock_history_settings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('cabinet_id')
                    ->constrained('wb_cabinets')
                    ->cascadeOnDelete();
                $table->unsignedTinyInteger('retention_days')->default(90);
                $table->boolean('stocks_tracking_enabled')->default(false);
                $table->boolean('orders_tracking_enabled')->default(false);
                $table->string('stocks_status', 32)->default('idle');
                $table->string('orders_status', 32)->default('idle');
                $table->text('stocks_last_error')->nullable();
                $table->text('orders_last_error')->nullable();
                $table->timestamp('products_synced_at')->nullable();
                $table->unsignedInteger('products_count')->default(0);
                $table->timestamps();

                $table->unique('cabinet_id');
            });
        }

        if (! Schema::hasTable('wb_stock_history_products')) {
            Schema::create('wb_stock_history_products', function (Blueprint $table) {
                $table->id();
                $table->foreignId('cabinet_id')
                    ->constrained('wb_cabinets')
                    ->cascadeOnDelete();
                $table->unsignedBigInteger('nm_id');
                $table->unsignedBigInteger('chrt_id')->default(0);
                $table->string('vendor_code')->nullable();
                $table->string('subject')->nullable();
                $table->string('name')->nullable();
                $table->string('tech_size')->nullable();
                $table->string('barcode')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['cabinet_id', 'nm_id', 'chrt_id'], 'wb_stock_hist_products_unique');
                $table->index(['cabinet_id', 'vendor_code'], 'wb_stock_hist_products_vendor_idx');
                $table->index(['cabinet_id', 'subject'], 'wb_stock_hist_products_subject_idx');
                $table->index(['cabinet_id', 'barcode'], 'wb_stock_hist_products_barcode_idx');
            });
        }

        if (! Schema::hasTable('wb_stock_history_warehouses')) {
            Schema::create('wb_stock_history_warehouses', function (Blueprint $table) {
                $table->id();
                $table->foreignId('cabinet_id')
                    ->constrained('wb_cabinets')
                    ->cascadeOnDelete();
                $table->string('warehouse_key', 191);
                $table->unsignedBigInteger('warehouse_id')->nullable();
                $table->string('warehouse_name');
                $table->timestamps();

                $table->unique(['cabinet_id', 'warehouse_key'], 'wb_stock_hist_wh_unique');
            });
        }

        if (! Schema::hasTable('wb_stock_history_days')) {
            Schema::create('wb_stock_history_days', function (Blueprint $table) {
                $table->id();
                $table->foreignId('cabinet_id')
                    ->constrained('wb_cabinets')
                    ->cascadeOnDelete();
                $table->date('stock_date');
                $table->timestamps();

                $table->unique(['cabinet_id', 'stock_date'], 'wb_stock_hist_days_unique');
            });
        }

        if (! Schema::hasTable('wb_stock_history_items')) {
            Schema::create('wb_stock_history_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('cabinet_id')
                    ->constrained('wb_cabinets')
                    ->cascadeOnDelete();
                $table->unsignedBigInteger('nm_id');
                $table->unsignedBigInteger('chrt_id')->default(0);
                $table->string('warehouse_key', 191);
                $table->date('stock_date');
                $table->unsignedInteger('qty');
                $table->timestamps();

                $table->unique(
                    ['cabinet_id', 'nm_id', 'chrt_id', 'warehouse_key', 'stock_date'],
                    'wb_stock_hist_items_unique'
                );
                $table->index(['cabinet_id', 'stock_date'], 'wb_stock_hist_items_date_idx');
                $table->index(['cabinet_id', 'nm_id', 'chrt_id'], 'wb_stock_hist_items_sku_idx');
            });
        }

        if (! Schema::hasTable('wb_order_history_days')) {
            Schema::create('wb_order_history_days', function (Blueprint $table) {
                $table->id();
                $table->foreignId('cabinet_id')
                    ->constrained('wb_cabinets')
                    ->cascadeOnDelete();
                $table->date('order_date');
                $table->timestamps();

                $table->unique(['cabinet_id', 'order_date'], 'wb_order_hist_days_unique');
            });
        }

        if (! Schema::hasTable('wb_order_history_items')) {
            Schema::create('wb_order_history_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('cabinet_id')
                    ->constrained('wb_cabinets')
                    ->cascadeOnDelete();
                $table->unsignedBigInteger('nm_id');
                $table->string('tech_size')->default('');
                $table->string('barcode')->default('');
                $table->string('vendor_code')->nullable();
                $table->string('subject')->nullable();
                $table->date('order_date');
                $table->unsignedInteger('orders_count')->default(0);
                $table->timestamps();

                $table->unique(
                    ['cabinet_id', 'nm_id', 'tech_size', 'barcode', 'order_date'],
                    'wb_order_hist_items_unique'
                );
                $table->index(['cabinet_id', 'order_date'], 'wb_order_hist_items_date_idx');
                $table->index(['cabinet_id', 'nm_id'], 'wb_order_hist_items_nm_idx');
                $table->index(['cabinet_id', 'vendor_code'], 'wb_order_hist_items_vendor_idx');
                $table->index(['cabinet_id', 'barcode'], 'wb_order_hist_items_barcode_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wb_order_history_items');
        Schema::dropIfExists('wb_order_history_days');
        Schema::dropIfExists('wb_stock_history_items');
        Schema::dropIfExists('wb_stock_history_days');
        Schema::dropIfExists('wb_stock_history_warehouses');
        Schema::dropIfExists('wb_stock_history_products');
        Schema::dropIfExists('wb_stock_history_settings');
    }
};
