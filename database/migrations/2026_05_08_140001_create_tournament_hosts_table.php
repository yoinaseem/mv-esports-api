<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tournament hosts: a capability table — one row per user who has applied
 * for or been granted host status (DESIGN.md §3). Hosting is vetted, not a
 * blanket role; a user may also be affiliated with an organization.
 *
 * status enum: pending / approved / suspended
 *  - pending   on application
 *  - approved  by a system_manager / superadmin
 *  - suspended for misconduct (irreversible at MVP; reactivate by hand)
 *
 * approved_by_user_id is SET NULL on delete so the host row survives a
 * deleted approver; the approval timestamp keeps the audit trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournament_hosts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('display_name');
            $table->text('bio')->nullable();
            $table->string('status')->default('pending');
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournament_hosts');
    }
};
