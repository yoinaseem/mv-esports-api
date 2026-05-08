<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * mv-esports user extensions:
 * - name relaxed to nullable (DESIGN.md §11: no real-name requirement)
 * - display_name added (optional public handle)
 * - date_of_birth required (drives the under-13 age gate at registration)
 * - country defaults to 'MV' (ISO 3166-1 alpha-2; project's primary market)
 * - softDeletes for the anonymisation flow described in DESIGN.md §11.5
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->nullable()->change();
            $table->string('display_name')->nullable()->after('name');
            $table->date('date_of_birth')->after('password');
            $table->string('country', 2)->nullable()->default('MV')->after('date_of_birth');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn(['country', 'date_of_birth', 'display_name']);
            $table->string('name')->nullable(false)->change();
        });
    }
};
