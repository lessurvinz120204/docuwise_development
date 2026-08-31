<?php

namespace App\Models;

use App\Events\AdminActivityLogged;
use App\Mail\ResetPasswordMail;
use App\Mail\VerifyAccountMail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\HasApiTokens;

/**
 * implements MustVerifyEmail — the base Authenticatable class already
 * includes the MustVerifyEmail and CanResetPassword TRAITS (see
 * vendor/laravel/framework/.../Foundation/Auth/User.php), so this is only
 * opting into the CONTRACT; no need to re-declare either trait here.
 * sendEmailVerificationNotification()/sendPasswordResetNotification() are
 * overridden below to send this app's own branded Mailables instead of
 * Laravel's default bare notification styling, matching the two-factor
 * code email (see AuthController::requestCode()) — the only three things
 * this app still sends by email. Every other event (document assigned,
 * a decision made, an auto-approval disputed) is deliberately in-app
 * notification only, not email — see NotificationRecord::send() at each
 * of those call sites; those Mailables existed once and were removed.
 */
class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $primaryKey = 'user_id';

    /**
     * Fixed department list, same lightweight "hardcoded, validated string"
     * pattern ValidationService::knownCategories() already uses for
     * document categories — not a separate Department table, for
     * consistency with how this app already models small fixed lists.
     */
    private const DEPARTMENTS = ['Engineering', 'Finance'];

    /** staff = ordinary functional-stage reviewer; head = sits on a category's Final Approval stage. */
    private const LEVELS = ['staff', 'head'];

    protected $fillable = [
        'username', 'password_hash', 'full_name', 'email', 'role', 'assigned_category', 'department', 'level', 'is_busy', 'created_by', 'is_active',
    ];

    protected $hidden = ['password_hash', 'remember_token', 'two_factor_code'];

    protected $casts = [
        'is_active' => 'boolean',
        'is_busy' => 'boolean',
        'email_verified_at' => 'datetime',
        'backup_codes_viewed_at' => 'datetime',
        'two_factor_expires_at' => 'datetime',
    ];

    /**
     * toggleAvailability() (ApprovalController) flips is_busy without
     * writing an audit log entry — this is the only path that changes,
     * so unlike everything else the admin dashboard's Approver Workload
     * panel depends on, it needs its own explicit live-update hook.
     */
    protected static function booted(): void
    {
        static::updated(function (self $user) {
            if ($user->wasChanged('is_busy')) {
                event(new AdminActivityLogged());
            }
        });
    }

    /**
     * Laravel's auth guard expects a `password` attribute/column by default.
     * We map it onto our documented `password_hash` column instead of
     * renaming the column, to stay faithful to the Data Dictionary (3.5.1).
     */
    public function getAuthPassword()
    {
        return $this->password_hash;
    }

    public function setPasswordAttribute($value)
    {
        $this->attributes['password_hash'] = $value;
    }

    /** Kept in sync with the live countdown shown on the two-factor screen (resources/views/auth/two-factor.blade.php) — change both together. */
    public const TWO_FACTOR_CODE_VALIDITY_SECONDS = 120;

    /**
     * Generates a fresh 6-digit login code, valid for
     * TWO_FACTOR_CODE_VALIDITY_SECONDS, and persists only its hash —
     * never the plain code — so a database dump alone can't hand over an
     * active login, same reasoning as password_hash itself. Returns the
     * plain code once, purely so the caller (AuthController::
     * requestTwoFactorCode()) can email it; nothing else ever reads it
     * back out.
     */
    public function generateTwoFactorCode(): string
    {
        $code = (string) random_int(100000, 999999);

        $this->forceFill([
            'two_factor_code' => Hash::make($code),
            'two_factor_expires_at' => now()->addSeconds(self::TWO_FACTOR_CODE_VALIDITY_SECONDS),
        ])->save();

        return $code;
    }

    /** False for an expired or already-cleared code, not just a wrong one — both fail the same way to the caller. */
    public function verifyTwoFactorCode(string $code): bool
    {
        if (!$this->two_factor_code || !$this->two_factor_expires_at || $this->two_factor_expires_at->isPast()) {
            return false;
        }

        return Hash::check($code, $this->two_factor_code);
    }

    /** Called after a successful verify (one-time use) and whenever a fresh login attempt supersedes a pending code. */
    public function clearTwoFactorCode(): void
    {
        $this->forceFill(['two_factor_code' => null, 'two_factor_expires_at' => null])->save();
    }

    /** Kept in sync with the sign-in page's own copy — change both together. */
    public const BACKUP_CODE_COUNT = 3;

    /**
     * Sign In Backup Codes — a login fallback that works for ANY reason
     * the emailed 2FA code doesn't reach someone (the mail service being
     * down, their own inbox full, spam-filtered, no connection right
     * then, whatever), not gated on detecting a specific cause. Called
     * on account creation and again automatically the instant someone's
     * last remaining code gets used, so an account is never left with
     * zero.
     *
     * Deliberately encrypted (Crypt::encryptString(), reversible with
     * APP_KEY), not hashed like password_hash/two_factor_code — an Admin
     * needs to be able to look a user's current codes back up
     * (password-gated, see AdminController::backupCodes()) for someone
     * who's lost theirs, which a one-way hash could never allow. Plain
     * 6-digit numeric, matching the emailed code's format — a smaller
     * code space (1,000,000 vs. an alphanumeric format's ~1.09 trillion)
     * but the shared login rate limiter (5 attempts/60s, see
     * ThrottlesAttempts) already makes brute-forcing either format
     * impractical, so the simpler, more familiar format won out.
     */
    public function generateBackupCodes(): void
    {
        $this->backupCodes()->delete();

        for ($i = 0; $i < self::BACKUP_CODE_COUNT; $i++) {
            $this->backupCodes()->create(['code' => Crypt::encryptString(self::randomBackupCode())]);
        }
    }

    private static function randomBackupCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Checks $code against every currently-unused backup code for this
     * account. On a match: marks that one code used (never deleted —
     * still shown as "Used on ..." in the Admin popup), then, if that
     * was the last unused one, immediately generates a fresh batch so
     * the account is never left without a working fallback.
     */
    public function verifyBackupCode(string $code): bool
    {
        $normalized = trim($code);

        foreach ($this->backupCodes()->unused()->get() as $backupCode) {
            try {
                if (Crypt::decryptString($backupCode->code) === $normalized) {
                    $backupCode->used_at = now();
                    $backupCode->save();

                    if ($this->backupCodes()->unused()->doesntExist()) {
                        $this->generateBackupCodes();
                    }

                    return true;
                }
            } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
                continue;
            }
        }

        return false;
    }

    public function backupCodes()
    {
        return $this->hasMany(UserBackupCode::class, 'user_id', 'user_id');
    }

    // --- Role helpers ---
    public function isAdmin(): bool { return $this->role === 'admin'; }
    public function isOriginator(): bool { return $this->role === 'originator'; }
    public function isApprover(): bool { return $this->role === 'approver'; }

    // --- Department / level helpers ---
    public function isHead(): bool { return $this->level === 'head'; }
    public function isStaffLevel(): bool { return $this->level === 'staff'; }

    public static function knownDepartments(): array
    {
        return self::DEPARTMENTS;
    }

    public static function knownLevels(): array
    {
        return self::LEVELS;
    }

    // --- Relationships ---
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }

    public function documentsOriginated()
    {
        return $this->hasMany(DocumentRepository::class, 'originator_id', 'user_id');
    }

    public function assignmentsAsApprover()
    {
        return $this->hasMany(DocumentAssignment::class, 'user_id', 'user_id');
    }

    public function slaViolations()
    {
        return $this->hasMany(SlaViolation::class, 'approver_id', 'user_id');
    }

    /**
     * Optional restriction to specific workflow stages within this
     * approver's assigned_category (Admin dynamic workflow assignment).
     * Empty by default, meaning "eligible for every stage in my category."
     */
    public function workflowStages()
    {
        return $this->belongsToMany(WorkflowStage::class, 'approver_workflow_stages', 'user_id', 'stage_id')
            ->withTimestamps();
    }

    public function notifications()
    {
        return $this->hasMany(NotificationRecord::class, 'recipient_id', 'user_id')->orderByDesc('created_at');
    }

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class, 'user_id', 'user_id');
    }

    /**
     * Overrides the MustVerifyEmail trait's default (which sends Laravel's
     * bare Illuminate\Auth\Notifications\VerifyEmail). Signed, expiring
     * link — same mechanism, just this app's own branded email instead of
     * the framework default. Deliberately NOT wrapped in a queued
     * Notification class: this app's other transactional emails are all
     * plain queued Mailables (see app/Mail/*), so this matches that
     * existing convention rather than introducing a second pattern.
     */
    public function sendEmailVerificationNotification(): void
    {
        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $this->getKey(), 'hash' => sha1($this->getEmailForVerification())]
        );

        Mail::to($this->email)->queue(new VerifyAccountMail($this, $url));
    }

    /**
     * Overrides the CanResetPassword trait's default (Illuminate\Auth\
     * Notifications\ResetPassword) for the same branding-consistency
     * reason as sendEmailVerificationNotification() above. $token is
     * already generated + stored by Password::sendResetLink() before this
     * is called — this only builds the URL and sends the email.
     */
    public function sendPasswordResetNotification($token): void
    {
        $url = route('password.reset', ['token' => $token, 'email' => $this->getEmailForPasswordReset()]);

        Mail::to($this->email)->queue(new ResetPasswordMail($this, $url));
    }
}