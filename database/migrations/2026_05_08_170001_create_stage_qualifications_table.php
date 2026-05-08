<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage qualifications: the dependency graph between stages.
 * source_stage_id is nullable — null means the rule pulls from the
 * tournament's registrations (the entry point for the earliest stage).
 *
 * rule_type enum: top_n / top_n_per_group / manual / all
 *  - top_n: take the top N from the source stage's final standings
 *  - top_n_per_group: take top N per group (cross-group placement)
 *  - manual: host populates target stage_participants directly
 *  - all: every source participant qualifies
 *
 * The graph must be a DAG; cycle prevention happens at the application
 * layer (NoCycleInQualificationGraph rule) since Postgres doesn't enforce
 * graph constraints.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stage_qualifications', function (Blueprint $table) {
            $table->id();
            // Nullable: null source means "from tournament registrations".
            $table->foreignId('source_stage_id')->nullable()->constrained('stages')->cascadeOnDelete();
            $table->foreignId('target_stage_id')->constrained('stages')->cascadeOnDelete();
            $table->string('rule_type');
            $table->json('rule_config')->nullable();
            $table->timestamps();

            // Lookups are typically "what feeds this stage?"
            $table->index('target_stage_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stage_qualifications');
    }
};
