<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Стратегия репрайсера «по конкурентам» удалена — таблица больше не используется.
     */
    public function up(): void
    {
        Schema::dropIfExists('wb_repricer_competitors');
    }

    public function down(): void
    {
        // Не восстанавливаем: инструмент удалён.
    }
};
