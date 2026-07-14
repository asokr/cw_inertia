<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('wb_search_requests');
    }

    public function down(): void
    {
        // Table removed intentionally; no rollback.
    }
};