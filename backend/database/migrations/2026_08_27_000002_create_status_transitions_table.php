<?php

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('status_transitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('from_status_id')->constrained('statuses')->restrictOnDelete();
            $table->foreignId('to_status_id')->constrained('statuses')->restrictOnDelete();
            $table->enum('required_role', UserRole::values())->nullable();
            $table->timestamps();
            $table->unique(['from_status_id', 'to_status_id'], 'status_transitions_edge_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_transitions');
    }
};
