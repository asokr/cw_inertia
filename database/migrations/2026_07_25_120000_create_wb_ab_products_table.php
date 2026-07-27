<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wb_ab_products')) {
            return;
        }

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
            $table->timestamps();

            $table->unique(['cabinet_id', 'nm_id']);
            $table->index(['cabinet_id', 'vendor_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wb_ab_products');
    }
};
