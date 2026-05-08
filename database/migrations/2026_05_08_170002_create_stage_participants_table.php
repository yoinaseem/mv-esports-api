<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage participants: resolved participants for a stage. Polymorphic
 * (team / player) using the morphMap registered in AppServiceProvider.
 *
 * Populated by:
 *  - Bracket generation (commit 8) for the earliest stage — copies from
 *    tournament_registrations.
 *  - Qualification resolution (commit 9) for downstream stages — copies
 *    from upstream stage standings per the rule_type.
 *  - The host directly via POST for stages with rule_type=manual.
 *
 * group_number is set when the stage's format uses groups (round_robin
 * with config.groups > 1). final_position is set when the stage
 * completes (commit 9).
 *
 * status enum: active / eliminated / withdrawn — driven by match
 * advancement in commit 9.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stage_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stage_id')->constrained('stages')->cascadeOnDelete();
            // morphs() creates participant_type (string alias) + participant_id
            // (unsignedBigInteger) plus a composite index.
            $table->morphs('participant');
            $table->integer('seed');
            $table->integer('group_number')->nullable();
            $table->string('status')->default('active');
            $table->integer('final_position')->nullable();
            $table->timestamps();

            // Common access patterns: standings (by seed), group lookup, status filter.
            $table->index(['stage_id', 'seed']);
            $table->index(['stage_id', 'group_number']);
            $table->index(['stage_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stage_participants');
    }
};
