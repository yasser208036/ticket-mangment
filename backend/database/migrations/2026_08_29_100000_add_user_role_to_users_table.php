<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE `users` MODIFY `role` ENUM('admin','agent','user') NOT NULL DEFAULT 'agent'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Narrowing the ENUM with rows still holding 'user' would silently coerce
        // them to '' under a non-strict sql_mode. Refuse instead: a rollback that
        // destroys account roles is worse than a rollback that stops.
        if (DB::table('users')->where('role', 'user')->exists()) {
            throw new RuntimeException('Cannot roll back: user accounts with role=user still exist. Reassign or delete them first.');
        }
        DB::statement("ALTER TABLE `users` MODIFY `role` ENUM('admin','agent') NOT NULL DEFAULT 'agent'");
    }
};
