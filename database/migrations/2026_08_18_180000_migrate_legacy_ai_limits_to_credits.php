<?php

use App\Services\Credits\LegacyCreditMigrationService;
use Database\Seeders\CreditPricingSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->extendUserAuditTable();
        $this->createPlanAuditTable();

        if (
            ! Schema::hasTable('subscribers_subscriptions')
            || ! Schema::hasTable('subscribers_plans')
            || ! Schema::hasTable('credit_accounts')
        ) {
            return;
        }

        (new CreditPricingSeeder)->run();

        app(LegacyCreditMigrationService::class)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_legacy_plan_migrations');

        if (! Schema::hasTable('credit_legacy_migrations')) {
            return;
        }

        Schema::table('credit_legacy_migrations', function (Blueprint $table) {
            foreach (['source_month_limits', 'subscription_credits', 'month_migrated_at', 'extra_migrated_at'] as $column) {
                if (Schema::hasColumn('credit_legacy_migrations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function extendUserAuditTable(): void
    {
        if (! Schema::hasTable('credit_legacy_migrations')) {
            return;
        }

        Schema::table('credit_legacy_migrations', function (Blueprint $table) {
            if (! Schema::hasColumn('credit_legacy_migrations', 'source_month_limits')) {
                $table->json('source_month_limits')->nullable()->after('source_extra_limits');
            }
            if (! Schema::hasColumn('credit_legacy_migrations', 'subscription_credits')) {
                $table->unsignedInteger('subscription_credits')->default(0)->after('purchased_credits');
            }
            if (! Schema::hasColumn('credit_legacy_migrations', 'month_migrated_at')) {
                $table->timestamp('month_migrated_at')->nullable()->after('ran_at');
            }
            if (! Schema::hasColumn('credit_legacy_migrations', 'extra_migrated_at')) {
                $table->timestamp('extra_migrated_at')->nullable()->after('month_migrated_at');
            }
        });

        if (Schema::hasColumn('credit_legacy_migrations', 'extra_migrated_at')) {
            DB::table('credit_legacy_migrations')
                ->whereNotNull('ran_at')
                ->whereNull('extra_migrated_at')
                ->update(['extra_migrated_at' => DB::raw('ran_at')]);
        }
    }

    private function createPlanAuditTable(): void
    {
        if (Schema::hasTable('credit_legacy_plan_migrations')) {
            return;
        }

        Schema::create('credit_legacy_plan_migrations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('plan_id')->unique();
            $table->json('source_month_limits');
            $table->json('quotes');
            $table->unsignedInteger('credits_written')->default(0);
            $table->unsignedInteger('previous_credits_per_period')->default(0);
            $table->unsignedInteger('new_credits_per_period')->default(0);
            $table->timestamp('ran_at')->nullable();
            $table->timestamps();
        });
    }
};
