<?php

namespace App\Models;

use App\Events\AdminActivityLogged;
use App\Mail\ResetPasswordMail;
use App\Mail\VerifyAccountMail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\HasApiTokens;

/**
 * implements MustVerifyEmail — the base Authenticatable class already
 * includes the MustVerifyEmail and CanResetPassword TRAITS (see
 * vendor/laravel/framework/.../Foundation/Auth/User.php), so this is only
 * opting into the CONTRACT; no need to re-declare either trait here.
 * sendEmailVerificationNotification()/sendPasswordResetNotification() are
 * overridden below to send this app's own branded Mailables instead of
 * Laravel's default bare notification styling, matching every other email
 * this app already sends (DocumentAssignedMail, DocumentDecisionMail, etc.).
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

    protected $hidden = ['password_hash', 'remember_token'];

    protected $casts = [
        'is_active' => 'boolean',
        'is_busy' => 'boolean',
        'email_verified_at' => 'datetime',
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