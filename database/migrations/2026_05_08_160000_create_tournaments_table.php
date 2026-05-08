<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tournaments: the top of the hierarchy (Tournament → Stage → Match).
 * Stages and matches are added in commits 6 and 7.
 *
 * FK behaviour:
 *  - game_id RESTRICT — a game in use can't be hard-deleted.
 *  - host_id SET NULL — host capability removal preserves the tournament.
 *  - organization_id SET NULL — org dissolution preserves the tournament.
 *  - created_by_user_id SET NULL — anonymisation flow per DESIGN.md §11.5
 *    leaves the tournament intact but unattributed.
 *  - approved_by_user_id SET NULL — same reason as TournamentHost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournaments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->foreignId('game_id')->constrained('games')->restrictOnDelete();
            $table->foreignId('host_id')->nullable()->constrained('tournament_hosts')->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            // enums backed by App\Enums; stored as plain strings.
            $table->string('participant_type'); // team / player
            $table->string('registration_type')->default('open'); // open / invite_only / signed_only
            $table->string('status')->default('draft_pending_review');
            $table->text('description')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->timestamp('registration_opens_at');
            $table->timestamp('registration_closes_at');
            $table->string('stream_url')->nullable();
            $table->string('banner_url')->nullable();
            $table->integer('max_participants')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Public listings of upcoming/active tournaments (DESIGN.md §15).
            $table->index(['status', 'start_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournaments');
    }
};
