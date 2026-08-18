<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('subscribers_plans') && Schema::hasColumn('subscribers_plans', 'limits_month')) {
            Schema::table('subscribers_plans', function (Blueprint $table) {
                $table->dropColumn('limits_month');
            });
        }

        if (Schema::hasTable('subscribers_subscriptions')) {
            Schema::table('subscribers_subscriptions', function (Blueprint $table) {
                $drop = [];
                foreach (['limits_month', 'extra_limits_month', 'extra_limits_plan'] as $column) {
                    if (Schema::hasColumn('subscribers_subscriptions', $column)) {
                        $drop[] = $column;
                    }
                }
                if ($drop !== []) {
                    $table->dropColumn($drop);
                }
            });
        }

        Schema::dropIfExists('extra_limits');

        if (
            Schema::hasTable('credit_accounts')
            && ! Schema::hasColumn('credit_accounts', 'subscription_exhausted_notified_period')
        ) {
            Schema::table('credit_accounts', function (Blueprint $table) {
                $table->string('subscription_exhausted_notified_period')->nullable()->after('last_granted_period_key');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('subscribers_plans') && ! Schema::hasColumn('subscribers_plans', 'limits_month')) {
            Schema::table('subscribers_plans', function (Blueprint $table) {
                $table->json('limits_month')->nullable();
            });
        }

        if (Schema::hasTable('subscribers_subscriptions')) {
            Schema::table('subscribers_subscriptions', function (Blueprint $table) {
                if (! Schema::hasColumn('subscribers_subscriptions', 'limits_month')) {
                    $table->json('limits_month')->nullable();
                }
                if (! Schema::hasColumn('subscribers_subscriptions', 'extra_limits_month')) {
                    $table->json('extra_limits_month')->nullable();
                }
                if (! Schema::hasColumn('subscribers_subscriptions', 'extra_limits_plan')) {
                    $table->json('extra_limits_plan')->nullable();
                }
            });
        }

        if (! Schema::hasTable('extra_limits')) {
            Schema::create('extra_limits', function (Blueprint $table) {
                $table->id();
                $table->string('slug')->unique();
                $table->string('name');
                $table->decimal('price', 12, 4)->default(0);
                $table->unsignedInteger('order')->default(0);
                $table->timestamps();
            });
        }

        if (
            Schema::hasTable('credit_accounts')
            && Schema::hasColumn('credit_accounts', 'subscription_exhausted_notified_period')
        ) {
            Schema::table('credit_accounts', function (Blueprint $table) {
                $table->dropColumn('subscription_exhausted_notified_period');
            });
        }
    }
};
