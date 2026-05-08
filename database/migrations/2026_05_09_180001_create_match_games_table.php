<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Match games: per-game scoring within a match. A best-of-5 has up to 5
 * match_games. Each game has its own winner (polymorphic) and per-side
 * raw scores (e.g. Valorant 13-7).
 *
 * When all match_games for a match's best_of threshold complete, the
 * match's score_a / score_b get recomputed by MatchGameObserver and
 * the match is ready to be marked Completed (driven by the advancement
 * service in commit 9).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_games', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->integer('game_number'); // 1-indexed
            $table->string('winner_participant_type')->nullable();
            $table->unsignedBigInteger('winner_participant_id')->nullable();
            $table->integer('score_a')->nullable(); // raw in-game score, e.g. 13
            $table->integer('score_b')->nullable();
            $table->string('map_or_mode')->nullable(); // "Bind", "Mannfield Night", etc.
            $table->string('status')->default('pending');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // One row per (match, game_number).
            $table->unique(['match_id', 'game_number']);
            $table->index(['winner_participant_type', 'winner_participant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_games');
    }
};
