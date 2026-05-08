<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Players: a user's competitive identity within a specific game. One row
 * per (user, game). user_id is nullable so a host can pre-create roster
 * entries for participants without platform accounts (DESIGN.md §4) — those
 * orphan players can be linked back to a user once they sign up (out of
 * scope for the MVP UI).
 *
 * Unique (user_id, game_id) plays nicely with Postgres null semantics:
 * NULLs are treated as distinct values in unique indexes, so multiple
 * orphan rows per game are allowed while non-null pairs stay unique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('game_id')->constrained('games')->restrictOnDelete();
            $table->string('gamertag');
            $table->string('rank_or_tier')->nullable();
            $table->timestamps();

            // Postgres treats NULL as distinct in UNIQUE — orphan players
            // (user_id IS NULL) don't conflict with each other; non-null
            // pairs still enforce one-player-per-(user, game).
            $table->unique(['user_id', 'game_id']);
            // Gamertag must be unique within a game.
            $table->unique(['game_id', 'gamertag']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('players');
    }
};
