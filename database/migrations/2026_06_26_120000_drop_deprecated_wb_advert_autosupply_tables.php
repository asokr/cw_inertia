<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('wb_adv_logs');
        Schema::dropIfExists('wb_adv_phrases');
        Schema::dropIfExists('wb_adv_ads');
        Schema::dropIfExists('wb_adv_settings');
        Schema::dropIfExists('wb_adverts_wbsubjects');
        Schema::dropIfExists('wb_adverts');
        Schema::dropIfExists('wb_autosupply_items');
        Schema::dropIfExists('wb_autosupply_cabinets');
        Schema::dropIfExists('wb_real_cabinets');
    }

    public function down(): void
    {
        throw new RuntimeException('Cannot restore dropped advert/autosupply tables.');
    }
};