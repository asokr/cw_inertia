<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('credit_services', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->string('billing_mode', 32);
            $table->unsignedInteger('amount')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('credit_service_price_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_service_id')->constrained('credit_services')->cascadeOnDelete();
            $table->string('param_key', 32);
            $table->string('param_value', 32);
            $table->unsignedInteger('amount');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(
                ['credit_service_id', 'param_key', 'param_value'],
                'credit_service_tiers_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_service_price_tiers');
        Schema::dropIfExists('credit_services');
        Schema::dropIfExists('credit_settings');
    }
};
