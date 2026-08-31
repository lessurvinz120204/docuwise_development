<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Null until the account holder has seen their Sign In Backup Codes once,
 * on their own first successful login (see AuthController::login()) — not
 * at account creation, and not via the Admin. Set the instant they're
 * flashed to that first login's session, so the "save these now" popup
 * (resources/views/layouts/app.blade.php) never shows twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('backup_codes_viewed_at')->nullable()->after('email_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('backup_codes_viewed_at');
        });
    }
};
