<?php

namespace App\Http\Controllers;

use App\Events\UserVerified;
use App\Http\Controllers\Concerns\ThrottlesAttempts;
use App\Mail\TwoFactorCodeMail;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController extends Controller
{
    use ThrottlesAttempts;

    public function showLogin()
    {
        return view('auth.login');
    }

    /**
     * Every field lives on one page/one form now (see auth/login.blade.php)
     * — email, password, AND the emailed code all submit together here.
     * "Get Code" (requestCode() below) is a separate AJAX action that runs
     * first, but the actual login only ever completes through this single
     * endpoint, which re-checks the password itself rather than trusting
     * that requestCode() already vouched for it.
     */
    public function login(Request $request)
    {
        // Request validation on every incoming payload (Section 3 requirement).
        // 'code' is nullable, not required, deliberately — a missing code
        // must fail the SAME way a wrong one does (verifyTwoFactorCode()
        // already treats '' as just another wrong guess), not short-circuit
        // validation before the account even gets a chance to hit the
        // unverified-email check below. The real page's own "Sign In" stays
        // disabled until a code exists (see login.blade.php) — this is the
        // server-side floor under that, not a relaxation of it.
        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:100'],
            'password' => ['required', 'string', 'min:8'],
            'code' => ['nullable', 'string'],
        ]);
        $credentials['code'] ??= '';

        $throttleKey = $this->throttleKeyFor('login', $credentials['email'], $request);

        if (($seconds = $this->secondsLockedOut($throttleKey)) !== null) {
            // login_retry_after is flashed separately from the error string
            // (rather than only embedding the number in the message) so the
            // view can drive a live client-side countdown instead of a
            // number that's already stale by the time the page renders.
            return back()->withErrors([
                'email' => "Too many login attempts. Try again in {$seconds} second(s).",
            ])->with('login_retry_after', $seconds)->onlyInput('email');
        }

        // Which field an eventual failure message attaches to, and what it
        // says — default assumes a wrong email/password, overwritten below
        // if the password was actually right and the CODE was the problem.
        $errorField = 'email';
        $errorMessage = null;

        // Eloquent/Auth facade builds a parameterized query internally — no
        // raw SQL string interpolation of user input anywhere in this app.
        if (Auth::attempt(['email' => $credentials['email'], 'password' => $credentials['password']])) {
            $user = Auth::user();

            // Correct credentials are not enough on their own — the account
            // must have been verified at least once (see User::
            // sendEmailVerificationNotification(), sent the moment an admin
            // creates the account). Undo the login rather than ever letting
            // an unverified session start.
            if (!$user->hasVerifiedEmail()) {
                Auth::logout();

                return back()->withErrors([
                    'email' => 'Please verify your email before logging in — check your inbox for the verification link we sent when your account was created.',
                ])->onlyInput('email');
            }

            // Tried as the emailed 6-digit code first, then as a Sign In
            // Backup Code — deliberately NOT gated on whether email is
            // actually known to be broken (see User::generateBackupCodes()'
            // docblock): a full inbox, a spam filter, no signal right then —
            // there are too many reasons the emailed code might not reach
            // someone to make backup codes conditional on detecting one
            // specific cause. Either one completes login identically.
            if ($user->verifyTwoFactorCode($credentials['code']) || $user->verifyBackupCode($credentials['code'])) {
                $user->clearTwoFactorCode();
                RateLimiter::clear($throttleKey);
                $request->session()->regenerate();

                AuditLog::record($user->user_id, null, 'login', "User {$user->username} logged in.");

                // Shown once, to the account holder themselves, on their
                // own first successful login — not to the Admin who
                // created the account (see layouts/app.blade.php, which
                // pops this open on whichever dashboard they land on).
                // Lazily generates a batch here too, same as
                // AdminController::backupCodes(), so an account that
                // predates this feature still gets one the first time it
                // matters instead of failing silently.
                if ($user->backup_codes_viewed_at === null) {
                    if ($user->backupCodes()->doesntExist()) {
                        $user->generateBackupCodes();
                    }

                    session()->flash('new_backup_codes', $user->backupCodes()->orderBy('id')->get()->map(fn ($c) => Crypt::decryptString($c->code))->all());
                    $user->forceFill(['backup_codes_viewed_at' => now()])->save();
                }

                return redirect()->intended($this->dashboardRouteFor($user->role));
            }

            // Correct password, but neither the emailed code nor a backup
            // code matched — undo the login and fall through to the SAME
            // lockout-counting failure path below as a wrong password,
            // rather than a separate uncapped counter. Without this, a
            // stolen password alone would let someone brute-force either
            // code with no rate limit at all, since a right password would
            // otherwise never touch this counter.
            Auth::logout();
            $errorField = 'code';
            $errorMessage = 'That code is incorrect or has expired.';
        }

        $hits = $this->recordFailedAttempt($throttleKey);

        if ($errorMessage === null) {
            // Always name the remaining count, including the 0 case (this
            // attempt just used the last slot) — falling back to a bare
            // "Invalid credentials." on that one attempt would silently
            // drop the only warning the user gets before the next failure
            // locks them out.
            $remaining = $this->maxAttempts - $hits;
            $errorMessage = $remaining > 0
                ? "Invalid credentials. {$remaining} attempt(s) remaining before temporary lockout."
                : 'Invalid credentials. This was your last attempt — the next failure will trigger a temporary lockout.';
        }

        return back()->withErrors([$errorField => $errorMessage])->onlyInput('email');
    }

    /**
     * The AJAX action behind the login page's "Get Code"/"Resend Code"
     * button — validates the email+password already typed into the form
     * (WITHOUT logging in — Auth::validate(), not Auth::attempt()) before
     * emailing anything, so this can't be used to spam an arbitrary
     * inbox by anyone who doesn't actually know that account's password.
     * Shares the exact same email+IP lockout counter as login() itself
     * (see throttleKeyFor()/recordFailedAttempt()) — otherwise this would
     * be an unlimited-attempts side door around login()'s own lockout.
     */
    public function requestCode(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:100'],
            'password' => ['required', 'string'],
        ]);

        $throttleKey = $this->throttleKeyFor('login', $credentials['email'], $request);

        if (($seconds = $this->secondsLockedOut($throttleKey)) !== null) {
            return response()->json(['message' => "Too many attempts. Try again in {$seconds} second(s)."], 429);
        }

        if (!Auth::validate(['email' => $credentials['email'], 'password' => $credentials['password']])) {
            $this->recordFailedAttempt($throttleKey);

            return response()->json(['message' => 'Invalid email or password.'], 422);
        }

        $user = User::where('email', $credentials['email'])->first();

        if (!$user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Please verify your email before logging in — check your inbox for the verification link we sent when your account was created.'], 422);
        }

        $code = $user->generateTwoFactorCode();

        // Sent synchronously (send(), not queue()) specifically so a
        // delivery failure is caught HERE and reported back to the user
        // right away — a queued mail failing later, in a worker, would
        // leave them staring at a code that's never coming with no
        // explanation. Sign In Backup Codes (User::verifyBackupCode())
        // work independently of this either way, so this failure is
        // never a dead end for them, just a heads-up.
        try {
            Mail::to($user->email)->send(new TwoFactorCodeMail($user, $code));
        } catch (\Throwable $e) {
            Log::error('Two-factor code email failed to send.', ['user_id' => $user->user_id, 'error' => $e->getMessage()]);

            return response()->json([
                'message' => 'We couldn\'t send your verification code — email delivery seems to be down right now. If you have a Sign In Backup Code, you can use that instead.',
            ], 503);
        }

        return response()->json(['expiresIn' => User::TWO_FACTOR_CODE_VALIDITY_SECONDS]);
    }

    // throttleKeyFor()/secondsLockedOut()/recordFailedAttempt() now live in
    // Concerns\ThrottlesAttempts — shared with AdminController::
    // backupCodes(), which needs the exact same lockout behavior.

    public function logout(Request $request)
    {
        $user = Auth::user();
        if ($user) {
            AuditLog::record($user->user_id, null, 'logout', "User {$user->username} logged out.");
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /**
     * Signed-link handler for the email sent by User::
     * sendEmailVerificationNotification() — deliberately reachable by a
     * guest (no auth middleware): this app blocks login entirely until
     * verified (see login() above), so the person clicking this link
     * cannot already be authenticated. The signature itself (id + sha1 of
     * the email, expiring) is what proves it's legitimate, not a session.
     */
    public function verifyEmail(Request $request, int $id, string $hash)
    {
        abort_unless($request->hasValidSignature(), 403, 'This verification link is invalid or has expired.');

        $user = User::findOrFail($id);

        abort_unless(hash_equals((string) $hash, sha1($user->getEmailForVerification())), 403, 'This verification link is invalid.');

        if (!$user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
            event(new UserVerified($user));

            AuditLog::record(null, null, 'email_verified', "Account #{$user->user_id} ({$user->username}) verified its email.");
        }

        return redirect()->route('login')->with('status', 'Your account is verified — you can now log in.');
    }

    public function showForgotPassword()
    {
        return view('auth.forgot-password');
    }

    /**
     * Deliberately reports success regardless of whether the email matches
     * a real account — a distinct "no account found" error would let
     * anyone probe which emails are registered in the system.
     */
    public function sendResetLink(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);

        Password::sendResetLink($request->only('email'));

        return back()->with('status', 'If that email is registered, a password reset link is on its way to it.');
    }

    public function showResetForm(Request $request, string $token)
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->query('email', ''),
        ]);
    }

    public function resetPassword(Request $request)
    {
        $validated = $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            // mixedCase()+numbers() require actual complexity, not just
            // length; uncompromised() checks the password against known
            // data breaches via a k-anonymity API (only a partial hash is
            // ever sent, never the real password) — a password can be
            // 8+ characters and still be "password1234", which this
            // blocks that the plain min:8 rule alone never caught.
            'password' => ['required', 'string', PasswordRule::min(8)->mixedCase()->numbers()->uncompromised(), 'confirmed'],
        ]);

        $status = Password::reset($validated, function (User $user, string $password) {
            $user->forceFill(['password' => Hash::make($password)])->save();

            AuditLog::record(null, null, 'password_reset', "Account #{$user->user_id} ({$user->username}) reset its own password.");
        });

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withErrors(['email' => __($status)]);
        }

        return redirect()->route('login')->with('status', 'Your password has been reset — you can now log in.');
    }

    private function dashboardRouteFor(string $role): string
    {
        return match ($role) {
            'admin' => route('admin.dashboard'),
            'approver' => route('approver.dashboard'),
            default => route('originator.dashboard'),
        };
    }
}
