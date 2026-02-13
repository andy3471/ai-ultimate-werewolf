<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('game_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('round');
            $table->string('phase');
            $table->string('type');
            $table->foreignUuid('actor_player_id')->nullable()->constrained('players')->nullOnDelete();
            $table->foreignUuid('target_player_id')->nullable()->constrained('players')->nullOnDelete();
            $table->json('data')->nullable();
            $table->boolean('is_public')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_events');
    }
};
