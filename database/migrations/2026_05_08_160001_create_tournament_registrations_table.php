<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tournament registrations: a polymorphic participant (team OR player)
 * registered for a tournament. participant_type uses the morph alias
 * registered in AppServiceProvider ('team' | 'player'), not FQCN.
 *
 * registered_by_user_id tracks who actually clicked "register" — used to
 * enforce one registration per user per tournament (a captain on two teams
 * can only register one of them; a user with a player profile and a team
 * captaincy can't register both even if both are valid for the tournament's
 * participant_type).
 *
 * Two Postgres partial unique indexes provide DB-level safety nets against
 * concurrent-insert races that the FormRequest+Rule chain alone cannot
 * close (read-then-write isn't atomic):
 *  - (tournament_id, participant_type, participant_id) WHERE status NOT IN
 *    ('rejected','withdrawn') — the same participant can't have two
 *    simultaneous active registrations.
 *  - (tournament_id, registered_by_user_id) WHERE status NOT IN
 *    ('rejected','withdrawn') — the same user can't register twice.
 * Postgres-specific (the WHERE clause makes them partial); we're committed
 * to Postgres for this project.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournament_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained('tournaments')->cascadeOnDelete();
            // morphs() creates participant_type (string) + participant_id
            // (unsignedBigInteger) plus a composite index.
            $table->morphs('participant');
            $table->foreignId('registered_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->integer('seed')->nullable();
            $table->timestamp('registered_at');
            $table->timestamps();

            $table->index(['tournament_id', 'status']);
            $table->index(['tournament_id', 'registered_by_user_id']);
        });

        // Partial unique indexes — Laravel's schema builder doesn't expose
        // these natively, so we drop to raw SQL. The application-layer
        // checks (Rules + after-callbacks) provide friendly error messages
        // for the common case; these indexes catch the race window where
        // two concurrent requests both pass app-level checks before either
        // commits.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX tournament_registrations_participant_active_unique
                ON tournament_registrations (tournament_id, participant_type, participant_id)
                WHERE status NOT IN ('rejected', 'withdrawn')
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX tournament_registrations_user_active_unique
                ON tournament_registrations (tournament_id, registered_by_user_id)
                WHERE status NOT IN ('rejected', 'withdrawn')
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('tournament_registrations');
    }
};
