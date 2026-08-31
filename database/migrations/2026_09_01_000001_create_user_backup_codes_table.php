<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sign In Backup Codes — a login-time fallback that works for ANY reason
 * someone can't get their emailed 2FA code (Brevo actually down, their
 * own mailbox full, spam-filtered, no signal, whatever) — not gated on
 * detecting a specific failure, always available. See User::
 * generateBackupCodes()/verifyBackupCode().
 *
 * `code` is stored ENCRYPTED (Laravel's Crypt, reversible with APP_KEY),
 * not hashed like password_hash/two_factor_code — a deliberate,
 * discussed trade-off: an Admin needs to be able to look a user's
 * current codes back up (password-gated — see AdminController::
 * backupCodes()), which a one-way hash could never allow. Encrypted is
 * still meaningfully safer than plain text: a raw database leak alone
 * doesn't hand over the codes without the app's separate encryption key
 * too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_backup_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users', 'user_id')->cascadeOnDelete();
            $table->text('code');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_backup_codes');
    }
};
