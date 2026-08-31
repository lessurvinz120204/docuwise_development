<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Sent on every successful password check — see AuthController::
 * requestCode(). Deliberately NOT ShouldQueue, unlike this app's other
 * transactional emails (VerifyAccountMail, ResetPasswordMail) — a
 * queued mailable still gets dispatched to the queue even when sent via
 * ->send() (Laravel's Mailer defers to the queue automatically for any
 * ShouldQueue mailable), which would hide a delivery failure from the
 * caller instead of letting it be caught and reported to the user right
 * away (see requestCode()'s try/catch around Mail::send()).
 */
class TwoFactorCodeMail extends Mailable
{
    use SerializesModels;

    public function __construct(
        public User $user,
        public string $code,
    ) {
    }

    public function build(): self
    {
        return $this->subject('Your sign-in code — ' . config('app.name'))
            ->markdown('emails.two-factor-code');
    }
}
