<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Team members: roster rows. Roles are captain / player / substitute.
 * left_at toggles the historical-roster pattern — past members are kept
 * for tournament-history integrity, not deleted.
 *
 * player_id is RESTRICT: a player who has ever been on a team can't be
 * hard-deleted without first removing the membership (or leaving them in
 * the orphan-player state via SET NULL on user_id). This protects past
 * tournament records from losing references.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('player_id')->constrained('players')->restrictOnDelete();
            $table->string('role'); // enum: captain / player / substitute
            $table->timestamp('joined_at');
            $table->timestamp('left_at')->nullable();
            $table->timestamps();

            // Active-roster lookup index per TABLES.md §8.
            $table->index(['team_id', 'left_at']);
            $table->index(['player_id', 'left_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_members');
    }
};
