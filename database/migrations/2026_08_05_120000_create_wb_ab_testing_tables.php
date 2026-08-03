<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Final A/B testing schema (single source of truth).
 *
 * Older staged migrations under database/migrations/*wb_ab* may still exist
 * for local history; after rollback, remove them and rely on this file only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wb_ab_products')) {
            Schema::create('wb_ab_products', function (Blueprint $table) {
                $table->id();
                $table->foreignId('cabinet_id')
                    ->constrained('wb_cabinets')
                    ->cascadeOnDelete();
                $table->unsignedBigInteger('nm_id');
                $table->string('vendor_code')->nullable();
                $table->string('title')->nullable();
                $table->string('brand')->nullable();
                $table->string('subject_name')->nullable();
                $table->string('photo_url', 1024)->nullable();
                $table->decimal('price', 12, 2)->nullable();
                $table->decimal('rating', 4, 2)->nullable();
                $table->timestamp('rating_updated_at')->nullable();
                $table->timestamps();

                $table->unique(['cabinet_id', 'nm_id']);
                $table->index(['cabinet_id', 'vendor_code']);
            });
        }

        if (! Schema::hasTable('wb_ab_experiments')) {
            Schema::create('wb_ab_experiments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ab_product_id')
                    ->constrained('wb_ab_products')
                    ->cascadeOnDelete();
                $table->foreignId('cabinet_id')
                    ->constrained('wb_cabinets')
                    ->cascadeOnDelete();
                $table->string('name');
                $table->string('status', 32)->default('draft');
                $table->unsignedTinyInteger('progress')->default(0);

                $table->unsignedBigInteger('wb_advert_id')->nullable();
                $table->string('wb_advert_name')->nullable();
                $table->timestamp('campaign_bound_at')->nullable();

                $table->unsignedInteger('impressions_per_photo')->nullable();
                $table->unsignedInteger('impressions_per_round')->nullable();
                $table->unsignedInteger('round_minutes')->nullable();
                $table->unsignedInteger('cpm')->nullable();

                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->text('error_message')->nullable();
                $table->unsignedBigInteger('winner_photo_id')->nullable();
                $table->timestamp('last_processed_at')->nullable();
                $table->unsignedTinyInteger('consecutive_failures')->default(0);

                $table->timestamps();

                $table->index(['cabinet_id', 'ab_product_id']);
                $table->index(['cabinet_id', 'status']);
                $table->index(['status', 'last_processed_at']);
                $table->index(['cabinet_id', 'wb_advert_id'], 'wb_ab_experiments_cabinet_advert_idx');
            });
        }

        if (! Schema::hasTable('wb_ab_campaigns')) {
            Schema::create('wb_ab_campaigns', function (Blueprint $table) {
                $table->id();
                $table->foreignId('cabinet_id')
                    ->constrained('wb_cabinets')
                    ->cascadeOnDelete();
                $table->unsignedBigInteger('wb_advert_id');
                $table->string('name');
                $table->string('bid_type', 32)->nullable();
                $table->string('payment_type', 16)->nullable();
                $table->foreignId('created_by_experiment_id')
                    ->nullable()
                    ->constrained('wb_ab_experiments')
                    ->nullOnDelete();
                $table->timestamps();

                $table->unique(['cabinet_id', 'wb_advert_id'], 'wb_ab_campaigns_cabinet_advert_unique');
            });
        }

        if (! Schema::hasTable('wb_ab_experiment_photos')) {
            Schema::create('wb_ab_experiment_photos', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ab_experiment_id')
                    ->constrained('wb_ab_experiments')
                    ->cascadeOnDelete();
                $table->foreignId('cabinet_id')
                    ->constrained('wb_cabinets')
                    ->cascadeOnDelete();
                $table->unsignedTinyInteger('sort_order')->default(0);
                $table->string('disk', 32)->default('private');
                $table->string('path');
                $table->string('original_name')->nullable();
                $table->string('mime', 64)->nullable();
                $table->unsignedInteger('size')->nullable();
                $table->timestamps();

                $table->index(['ab_experiment_id', 'sort_order']);
                $table->index('cabinet_id');
            });
        }

        if (
            Schema::hasTable('wb_ab_experiments')
            && Schema::hasTable('wb_ab_experiment_photos')
            && Schema::hasColumn('wb_ab_experiments', 'winner_photo_id')
        ) {
            try {
                Schema::table('wb_ab_experiments', function (Blueprint $table) {
                    $table->foreign('winner_photo_id')
                        ->references('id')
                        ->on('wb_ab_experiment_photos')
                        ->nullOnDelete();
                });
            } catch (\Throwable) {
                // FK already exists on re-run / partial environments.
            }
        }

        if (! Schema::hasTable('wb_ab_experiment_cycles')) {
            Schema::create('wb_ab_experiment_cycles', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ab_experiment_id')
                    ->constrained('wb_ab_experiments')
                    ->cascadeOnDelete();
                $table->foreignId('cabinet_id')
                    ->constrained('wb_cabinets')
                    ->cascadeOnDelete();
                $table->foreignId('ab_experiment_photo_id')
                    ->constrained('wb_ab_experiment_photos')
                    ->cascadeOnDelete();
                $table->unsignedInteger('sequence')->default(1);
                $table->timestamp('started_at');
                $table->timestamp('ended_at')->nullable();
                $table->string('end_reason', 32)->nullable();
                $table->unsignedBigInteger('views_start')->default(0);
                $table->unsignedBigInteger('views_end')->nullable();
                $table->unsignedBigInteger('clicks_start')->default(0);
                $table->unsignedBigInteger('clicks_end')->nullable();
                $table->decimal('spend_start', 14, 2)->default(0);
                $table->decimal('spend_end', 14, 2)->nullable();
                $table->unsignedInteger('orders_start')->default(0);
                $table->unsignedInteger('orders_end')->nullable();
                $table->timestamps();

                $table->index(['ab_experiment_id', 'sequence']);
                $table->index(['ab_experiment_id', 'ended_at']);
                $table->index('ab_experiment_photo_id');
            });
        }

        if (! Schema::hasTable('wb_ab_experiment_events')) {
            Schema::create('wb_ab_experiment_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ab_experiment_id')
                    ->constrained('wb_ab_experiments')
                    ->cascadeOnDelete();
                $table->foreignId('cabinet_id')
                    ->constrained('wb_cabinets')
                    ->cascadeOnDelete();
                $table->string('type', 64);
                $table->string('message', 500);
                $table->json('meta')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['ab_experiment_id', 'created_at']);
                $table->index(['cabinet_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        if (
            Schema::hasTable('wb_ab_experiments')
            && Schema::hasColumn('wb_ab_experiments', 'winner_photo_id')
        ) {
            Schema::table('wb_ab_experiments', function (Blueprint $table) {
                try {
                    $table->dropForeign(['winner_photo_id']);
                } catch (\Throwable) {
                    // ignore if FK missing
                }
            });
        }

        Schema::dropIfExists('wb_ab_experiment_events');
        Schema::dropIfExists('wb_ab_experiment_cycles');
        Schema::dropIfExists('wb_ab_experiment_photos');
        Schema::dropIfExists('wb_ab_campaigns');
        Schema::dropIfExists('wb_ab_experiments');
        Schema::dropIfExists('wb_ab_products');
    }
};
