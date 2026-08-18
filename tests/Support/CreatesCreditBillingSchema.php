<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesCreditBillingSchema
{
    protected function setupCreditBillingSchema(): void
    {
        if (! Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('permissions')) {
            Schema::create('permissions', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('model_has_roles')) {
            Schema::create('model_has_roles', function (Blueprint $table) {
                $table->unsignedBigInteger('role_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->index(['model_id', 'model_type']);
            });
        }

        if (! Schema::hasTable('model_has_permissions')) {
            Schema::create('model_has_permissions', function (Blueprint $table) {
                $table->unsignedBigInteger('permission_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->index(['model_id', 'model_type']);
            });
        }

        if (! Schema::hasTable('role_has_permissions')) {
            Schema::create('role_has_permissions', function (Blueprint $table) {
                $table->unsignedBigInteger('permission_id');
                $table->unsignedBigInteger('role_id');
            });
        }

        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('name')->nullable();
                $table->string('surname')->default('');
                $table->string('email')->unique();
                $table->timestamp('email_verified_at')->nullable();
                $table->string('password')->nullable();
                $table->rememberToken();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('subscribers')) {
            Schema::create('subscribers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->unsignedTinyInteger('status')->default(1);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('subscribers_plans')) {
            Schema::create('subscribers_plans', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->decimal('price', 10, 2)->default(0);
                $table->unsignedInteger('duration')->default(30);
                $table->text('description')->nullable();
                $table->json('limits_plan')->nullable();
                $table->json('limits_month')->nullable(); // leftover для разовой миграции 180000
                $table->unsignedInteger('credits_per_period')->default(0);
                $table->json('permissions')->nullable();
                $table->unsignedTinyInteger('status')->default(1);
                $table->unsignedTinyInteger('hidden')->default(0);
                $table->timestamps();
            });
        } elseif (! Schema::hasColumn('subscribers_plans', 'credits_per_period')) {
            Schema::table('subscribers_plans', function (Blueprint $table) {
                $table->unsignedInteger('credits_per_period')->default(0);
            });
        }

        if (! Schema::hasTable('subscribers_subscriptions')) {
            Schema::create('subscribers_subscriptions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('subscribers_id');
                $table->unsignedBigInteger('plan_id');
                $table->json('limits_plan')->nullable();
                $table->json('extra_limits_plan')->nullable();
                $table->json('limits_month')->nullable();
                $table->json('extra_limits_month')->nullable();
                $table->timestamp('start_date')->nullable();
                $table->timestamp('end_date')->nullable();
                $table->unsignedTinyInteger('status')->default(1);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('credit_accounts')) {
            Schema::create('credit_accounts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->unique();
                $table->unsignedInteger('subscription_balance')->default(0);
                $table->unsignedInteger('purchased_balance')->default(0);
                $table->unsignedInteger('subscription_held')->default(0);
                $table->unsignedInteger('purchased_held')->default(0);
                $table->string('last_granted_period_key')->nullable();
                $table->string('subscription_exhausted_notified_period')->nullable();
                $table->timestamps();
            });
        } elseif (! Schema::hasColumn('credit_accounts', 'subscription_exhausted_notified_period')) {
            Schema::table('credit_accounts', function (Blueprint $table) {
                $table->string('subscription_exhausted_notified_period')->nullable();
            });
        }

        if (! Schema::hasTable('credit_ledger')) {
            Schema::create('credit_ledger', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
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
                $table->unsignedBigInteger('admin_user_id')->nullable();
                $table->nullableMorphs('related');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('credit_holds')) {
            Schema::create('credit_holds', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->unsignedInteger('amount');
                $table->unsignedInteger('subscription_reserved')->default(0);
                $table->unsignedInteger('purchased_reserved')->default(0);
                $table->string('status', 16);
                $table->string('idempotency_key')->unique();
                $table->string('service_code')->nullable();
                $table->json('operation_params')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('balances')) {
            Schema::create('balances', function (Blueprint $table) {
                $table->id();
                $table->morphs('payable');
                $table->decimal('value', 16, 8)->default(0);
                $table->decimal('value_pending', 16, 8)->default(0);
                $table->decimal('value_on_hold', 16, 8)->default(0);
                $table->string('currency', 10)->index();
                $table->unique(['payable_id', 'payable_type', 'currency'], 'unique_balance');
            });
        }

        if (! Schema::hasTable('transactions')) {
            Schema::create('transactions', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->nullableMorphs('from');
                $table->nullableMorphs('to');
                $table->decimal('amount', 64, 0)->default(0);
                $table->decimal('commission', 64, 0)->default(0);
                $table->decimal('received', 64, 0)->default(0);
                $table->string('currency', 10)->index();
                $table->string('status')->nullable();
                $table->string('processor_id')->nullable();
                $table->json('meta')->nullable();
                $table->boolean('archived')->default(false);
                $table->boolean('invisible')->default(false);
                $table->unsignedBigInteger('batch')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        } elseif (! Schema::hasColumn('transactions', 'commission')) {
            Schema::table('transactions', function (Blueprint $table) {
                if (! Schema::hasColumn('transactions', 'commission')) {
                    $table->decimal('commission', 64, 0)->default(0);
                }
                if (! Schema::hasColumn('transactions', 'archived')) {
                    $table->boolean('archived')->default(false);
                }
                if (! Schema::hasColumn('transactions', 'invisible')) {
                    $table->boolean('invisible')->default(false);
                }
                if (! Schema::hasColumn('transactions', 'processor_id')) {
                    $table->string('processor_id')->nullable();
                }
                if (! Schema::hasColumn('transactions', 'batch')) {
                    $table->unsignedBigInteger('batch')->nullable();
                }
                if (! Schema::hasColumn('transactions', 'uuid')) {
                    $table->uuid('uuid')->nullable();
                }
            });
        }

        if (! Schema::hasTable('credit_legacy_migrations')) {
            Schema::create('credit_legacy_migrations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->unique();
                $table->json('source_extra_limits');
                $table->json('source_month_limits')->nullable();
                $table->json('coefficients');
                $table->unsignedInteger('purchased_credits')->default(0);
                $table->unsignedInteger('subscription_credits')->default(0);
                $table->timestamp('ran_at')->nullable();
                $table->timestamp('month_migrated_at')->nullable();
                $table->timestamp('extra_migrated_at')->nullable();
                $table->timestamps();
            });
        } else {
            Schema::table('credit_legacy_migrations', function (Blueprint $table) {
                if (! Schema::hasColumn('credit_legacy_migrations', 'source_month_limits')) {
                    $table->json('source_month_limits')->nullable();
                }
                if (! Schema::hasColumn('credit_legacy_migrations', 'subscription_credits')) {
                    $table->unsignedInteger('subscription_credits')->default(0);
                }
                if (! Schema::hasColumn('credit_legacy_migrations', 'month_migrated_at')) {
                    $table->timestamp('month_migrated_at')->nullable();
                }
                if (! Schema::hasColumn('credit_legacy_migrations', 'extra_migrated_at')) {
                    $table->timestamp('extra_migrated_at')->nullable();
                }
            });
        }

        if (! Schema::hasTable('credit_legacy_plan_migrations')) {
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

        $this->setupCreditPricingSchema();
    }

    protected function setupCreditPricingSchema(): void
    {
        if (! Schema::hasTable('credit_settings')) {
            Schema::create('credit_settings', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->text('value')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('credit_services')) {
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
        }

        if (! Schema::hasTable('credit_service_price_tiers')) {
            Schema::create('credit_service_price_tiers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('credit_service_id');
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
    }
}
