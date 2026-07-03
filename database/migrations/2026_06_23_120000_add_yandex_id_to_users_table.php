<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('users', 'yandex_id')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'vk_id')) {
                $table->string('yandex_id')->nullable()->after('vk_id');
                return;
            }

            $table->string('yandex_id')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'yandex_id')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('yandex_id');
        });
    }
};
