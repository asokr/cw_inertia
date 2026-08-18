<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('subscription_balance')->default(0);
            $table->unsignedInteger('purchased_balance')->default(0);
            $table->unsignedInteger('subscription_held')->default(0);
            $table->unsignedInteger('purchased_held')->default(0);
            $table->string('last_granted_period_key')->nullable();
            $table->timestamps();
        });

        Schema::create('credit_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('direction', 16);
            $table->unsignedInteger('amount')->default(0);
            $table->integer('subscription_delta')->default(0);
            $table->integer('purchased_delta')->default(0);
            $table->unsignedInteger('subscription_balance_after')->default(0);
            $table->unsignedInteger('purchased_balance_after')->default(0);
            $table->unsignedInteger('available_after')->default(0);
            $table->json('source_split')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->string('period_key')->nullable();
            $table->string('service_code')->nullable();
            $table->json('operation_params')->nullable();
            $table->string('description')->nullable();
            $table->string('user_label')->nullable();
            $table->foreignId('admin_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->nullableMorphs('related');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'type']);
            $table->index('period_key');
        });

        Schema::create('credit_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('amount');
            $table->unsignedInteger('subscription_reserved')->default(0);
            $table->unsignedInteger('purchased_reserved')->default(0);
            $table->string('status', 16);
            $table->string('idempotency_key')->unique();
            $table->string('service_code')->nullable();
            $table->json('operation_params')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
            $table->index('user_id');
        });

        Schema::create('credit_legacy_migrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('source_extra_limits');
            $table->json('coefficients');
            $table->unsignedInteger('purchased_credits')->default(0);
            $table->timestamp('ran_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_legacy_migrations');
        Schema::dropIfExists('credit_holds');
        Schema::dropIfExists('credit_ledger');
        Schema::dropIfExists('credit_accounts');
    }
};
