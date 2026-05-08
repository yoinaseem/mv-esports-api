<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Matches: the bracket nodes themselves. Created by the bracket generator
 * (commit 8) from stage config + stage_participants. Each match has up to
 * two participants (polymorphic team / player), a winner once decided,
 * and self-referential advancement FKs that point at the next match in
 * the winners and losers brackets.
 *
 * The four advancement FKs are SET NULL because cascading would chain
 * arbitrarily through the bracket; SET NULL preserves data integrity if
 * a match is somehow cancelled or removed mid-tournament.
 *
 * score_a and score_b are derived from match_games (count of games won
 * by each participant) but stored explicitly for query simplicity. The
 * MatchGameObserver keeps them in sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stage_id')->constrained('stages')->cascadeOnDelete();
            $table->integer('bracket_round'); // 1-indexed
            $table->integer('bracket_position'); // 0-indexed within round
            $table->string('bracket_type'); // enum: winners / losers / grand_final / group
            $table->integer('group_number')->nullable(); // for round-robin groups

            // Polymorphic participants — A, B, and winner are all morphs
            // resolved via the morphMap in AppServiceProvider.
            $table->string('participant_a_type')->nullable();
            $table->unsignedBigInteger('participant_a_id')->nullable();
            $table->string('participant_b_type')->nullable();
            $table->unsignedBigInteger('participant_b_id')->nullable();
            $table->string('winner_participant_type')->nullable();
            $table->unsignedBigInteger('winner_participant_id')->nullable();

            // Derived from match_games via the MatchGameObserver.
            $table->integer('score_a')->default(0);
            $table->integer('score_b')->default(0);
            $table->integer('best_of')->default(1);

            // Self-referential advancement. SET NULL on delete so chains
            // don't cascade arbitrarily through the bracket.
            $table->foreignId('winner_advances_to_match_id')->nullable()
                ->constrained('matches')->nullOnDelete();
            $table->string('winner_advances_to_slot')->nullable(); // 'a' | 'b'
            $table->foreignId('loser_advances_to_match_id')->nullable()
                ->constrained('matches')->nullOnDelete();
            $table->string('loser_advances_to_slot')->nullable();

            $table->string('status')->default('pending');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // Common access patterns: render bracket (round + position),
            // filter by bracket type within a stage, find pending matches.
            $table->index(['stage_id', 'bracket_round', 'bracket_position']);
            $table->index(['stage_id', 'bracket_type', 'bracket_round']);
            $table->index(['stage_id', 'status']);
            $table->index(['participant_a_type', 'participant_a_id']);
            $table->index(['participant_b_type', 'participant_b_id']);
            $table->index(['winner_participant_type', 'winner_participant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matches');
    }
};
