<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stages: one tournament → many stages (group stage → playoffs → grand final etc.).
 * sort_order locks the playthrough sequence; bracket generation reads it
 * to know which stage to seed first. format drives which generator runs
 * downstream in commit 8.
 *
 * Status transitions: pending → in_progress (bracket generated, commit 8)
 * → completed (final stage-match completes, commit 9). No cancelled —
 * cancellation cascades from the tournament level.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained('tournaments')->cascadeOnDelete();
            $table->string('name');
            // enum: single_elim / double_elim / round_robin / swiss
            $table->string('format');
            $table->integer('sort_order');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status')->default('pending');
            // Format-specific config — JSON blob validated at the API layer.
            $table->json('config')->nullable();
            $table->timestamps();

            // Sort order unique within a tournament; reorder endpoint must
            // mutate atomically to avoid temporary collisions.
            $table->unique(['tournament_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stages');
    }
};
