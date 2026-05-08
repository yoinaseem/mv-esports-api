<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // enum: owner / staff / member. Stored as string + cast on the model.
            $table->string('role');
            $table->timestamp('joined_at');
            $table->timestamp('left_at')->nullable();
            $table->timestamps();

            // Lookup index for active-membership scopes. Conditional uniqueness
            // on (organization_id, user_id) where left_at IS NULL is enforced
            // app-side — Postgres supports partial unique indexes but Laravel's
            // schema builder doesn't expose them ergonomically. Worth revisiting
            // if user-membership writes get hot.
            $table->index(['organization_id', 'left_at']);
            $table->index(['user_id', 'left_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_members');
    }
};
