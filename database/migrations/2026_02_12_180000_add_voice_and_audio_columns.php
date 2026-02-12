<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->string('voice')->nullable()->after('order');
        });

        Schema::table('game_events', function (Blueprint $table) {
            $table->string('audio_url')->nullable()->after('is_public');
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropColumn('voice');
        });

        Schema::table('game_events', function (Blueprint $table) {
            $table->dropColumn('audio_url');
        });
    }
};
