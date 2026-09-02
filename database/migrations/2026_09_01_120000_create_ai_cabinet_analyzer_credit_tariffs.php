<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_cabinet_analyzer_credit_tariffs')) {
            Schema::create('ai_cabinet_analyzer_credit_tariffs', function (Blueprint $table) {
                $table->id();
                $table->string('provider', 32);
                $table->string('model', 120);
                $table->decimal('input_credits_per_1k', 12, 6);
                $table->decimal('output_credits_per_1k', 12, 6);
                $table->decimal('coefficient', 8, 4)->default(1);
                $table->boolean('is_default')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['provider', 'model'], 'ai_cab_analyzer_tariffs_provider_model_unique');
                $table->index(['provider', 'is_default', 'is_active'], 'ai_cab_tariffs_lookup_index');
            });
        } elseif (! $this->hasIndex('ai_cabinet_analyzer_credit_tariffs', 'ai_cab_tariffs_lookup_index')) {
            Schema::table('ai_cabinet_analyzer_credit_tariffs', function (Blueprint $table) {
                $table->index(['provider', 'is_default', 'is_active'], 'ai_cab_tariffs_lookup_index');
            });
        }

        if (! Schema::hasTable('ai_cabinet_analyzer_credit_charges')) {
            Schema::create('ai_cabinet_analyzer_credit_charges', function (Blueprint $table) {
                $table->id();
                $table->string('marketplace', 16);
                $table->string('analysis_type');
                $table->unsignedBigInteger('analysis_id');
                $table->unsignedBigInteger('user_id');
                $table->string('provider', 32)->nullable();
                $table->string('model', 120)->nullable();
                $table->unsignedInteger('input_tokens')->default(0);
                $table->unsignedInteger('output_tokens')->default(0);
                $table->unsignedInteger('total_tokens')->default(0);
                $table->unsignedInteger('credits_reserved')->default(0);
                $table->unsignedInteger('credits_charged')->default(0);
                $table->json('tariff_snapshot')->nullable();
                $table->string('credit_idempotency_key')->nullable();
                $table->timestamps();

                $table->index(['analysis_type', 'analysis_id'], 'ai_cab_charges_analysis_index');
                $table->index('user_id', 'ai_cab_charges_user_index');
                $table->index('credit_idempotency_key', 'ai_cab_charges_idempotency_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_cabinet_analyzer_credit_charges');
        Schema::dropIfExists('ai_cabinet_analyzer_credit_tariffs');
    }

    private function hasIndex(string $table, string $index): bool
    {
        foreach (Schema::getIndexes($table) as $row) {
            if (($row['name'] ?? '') === $index) {
                return true;
            }
        }

        return false;
    }
};
