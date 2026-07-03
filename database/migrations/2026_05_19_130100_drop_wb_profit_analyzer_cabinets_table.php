<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::dropIfExists('wb_profit_analyzer_cabinets');
    }

    public function down(): void
    {
        if (Schema::hasTable('wb_profit_analyzer_cabinets')) {
            return;
        }

        Schema::create('wb_profit_analyzer_cabinets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('name');
            $table->text('apikey');
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }
};
