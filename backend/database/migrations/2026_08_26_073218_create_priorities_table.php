<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('priorities', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->unsignedTinyInteger('level')->unique();
            $table->char('color', 7)->default('#6B7280');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_default_unique')->virtualAs('if(`is_default` = 1, 1, null)');
            $table->unique('is_default_unique', 'priorities_single_default_unique');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('priorities');
    }
};
