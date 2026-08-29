<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            // Serves `sort=escalated_at`: without it, ordering by a nullable
            // timestamp on a growing table is a filesort. Separate from
            // escalation_level's index, which answers the `escalated` filter
            // rather than the sort.
            $table->index('escalated_at');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropIndex(['escalated_at']);
        });
    }
};
