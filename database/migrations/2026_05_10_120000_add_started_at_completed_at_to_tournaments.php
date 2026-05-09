<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two timestamps stamping the actual start and end of a tournament,
 * distinct from the host-set `start_date` / `end_date` which are intent
 * (display + planning).
 *
 * - `started_at` is set when SeedAndBuildService flips the tournament
 *   from RegistrationClosed → InProgress.
 * - `completed_at` is set when TournamentCompletion flips the tournament
 *   from InProgress → Completed (cascaded from the final stage's final
 *   match).
 *
 * Cancelled tournaments leave `completed_at` null — `completed_at`
 * specifically means "ran to completion", not "ended for any reason".
 * The status enum tells you whether a tournament was cancelled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->timestamp('started_at')->nullable()->after('registration_closes_at');
            $table->timestamp('completed_at')->nullable()->after('started_at');
        });
    }

    public function down(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropColumn(['started_at', 'completed_at']);
        });
    }
};
