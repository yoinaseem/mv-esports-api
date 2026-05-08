<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Match events: append-only audit + live-feed source. Drives the
 * polling-based viewer updates per DESIGN.md §2. Each significant
 * action (score update, status change, walkover, participant assignment,
 * game completion) emits a row.
 *
 * No `updated_at` — events are immutable. The model overrides
 * $timestamps to manage `created_at` only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->string('event_type');
            $table->json('payload')->nullable();
            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');

            $table->index(['match_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_events');
    }
};
