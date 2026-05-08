<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Teams: per-game competitive units. organization_id is nullable per
 * DESIGN.md §5 — most amateur teams are pickup / community groups, not
 * signed to orgs. Some happen to be org-owned.
 *
 * created_by_player_id is the original captain in spirit; their authority
 * doesn't change as captaincy moves around the roster (team_members.role).
 * RESTRICT on the player FK so a creator can't be hard-deleted while their
 * teams exist — they have to transfer or delete first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('game_id')->constrained('games')->restrictOnDelete();
            $table->string('name');
            $table->string('tag', 8)->nullable();
            $table->string('logo_url')->nullable();
            $table->foreignId('created_by_player_id')->constrained('players')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // Team names unique within a game (per TABLES.md §7).
            $table->unique(['game_id', 'name']);
            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teams');
    }
};
