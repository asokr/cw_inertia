<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('oz_ab_experiment_photos')) {
            Schema::table('oz_ab_experiment_photos', function (Blueprint $table) {
                if (! Schema::hasColumn('oz_ab_experiment_photos', 'content_md5')) {
                    $table->string('content_md5', 32)->nullable()->after('size');
                }
                if (! Schema::hasColumn('oz_ab_experiment_photos', 'content_hash')) {
                    $table->string('content_hash', 16)->nullable()->after('content_md5');
                }
            });
        }

        if (Schema::hasTable('oz_ab_experiment_cycles')) {
            Schema::table('oz_ab_experiment_cycles', function (Blueprint $table) {
                if (! Schema::hasColumn('oz_ab_experiment_cycles', 'photo_confirmed_at')) {
                    $table->timestamp('photo_confirmed_at')->nullable()->after('started_at');
                }
                if (! Schema::hasColumn('oz_ab_experiment_cycles', 'last_seen_primary_url')) {
                    $table->string('last_seen_primary_url', 1024)->nullable()->after('photo_confirmed_at');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('oz_ab_experiment_photos')) {
            Schema::table('oz_ab_experiment_photos', function (Blueprint $table) {
                if (Schema::hasColumn('oz_ab_experiment_photos', 'content_hash')) {
                    $table->dropColumn('content_hash');
                }
                if (Schema::hasColumn('oz_ab_experiment_photos', 'content_md5')) {
                    $table->dropColumn('content_md5');
                }
            });
        }

        if (Schema::hasTable('oz_ab_experiment_cycles')) {
            Schema::table('oz_ab_experiment_cycles', function (Blueprint $table) {
                if (Schema::hasColumn('oz_ab_experiment_cycles', 'last_seen_primary_url')) {
                    $table->dropColumn('last_seen_primary_url');
                }
                if (Schema::hasColumn('oz_ab_experiment_cycles', 'photo_confirmed_at')) {
                    $table->dropColumn('photo_confirmed_at');
                }
            });
        }
    }
};
