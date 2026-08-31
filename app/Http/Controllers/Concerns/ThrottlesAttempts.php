<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Shared by AuthController's own login (wrong password OR wrong code) and
 * AdminController::backupCodes() (an Admin verifying a target user's
 * password to view their Sign In Backup Codes) — extracted so both share
 * IDENTICAL lockout behavior (same attempt count, same decay window, same
 * "reset the timer to a full window the instant the cap trips" fix)
 * rather than two copies that could quietly drift out of sync.
 */
trait ThrottlesAttempts
{
    private int $maxAttempts = 5;
    private int $decaySeconds = 60;

    /** Keyed by identifier+IP (the same approach Laravel's own Breeze starter kit uses) rather than IP alone, so one account's mistakes can't lock out everyone else behind the same NAT/office network. $scope namespaces different throttled actions (e.g. "login" vs "backup-codes:{$targetUserId}") so they never share a counter by accident. */
    private function throttleKeyFor(string $scope, string $identifier, Request $request): string
    {
        return $scope . ':' . Str::lower($identifier) . '|' . $request->ip();
    }

    /** Seconds remaining if this key is currently locked out, else null. */
    private function secondsLockedOut(string $throttleKey): ?int
    {
        if (!RateLimiter::tooManyAttempts($throttleKey, $this->maxAttempts)) {
            return null;
        }

        return RateLimiter::availableIn($throttleKey);
    }

    /** Records one failed attempt against the given lockout counter. Returns the running hit count so the caller can report how many attempts remain. */
    private function recordFailedAttempt(string $throttleKey): int
    {
        $hits = RateLimiter::hit($throttleKey, $this->decaySeconds);

        // RateLimiter::hit() sets its internal decay timer via a cache
        // add() — a no-op once the key already exists — so the timer is
        // only ever established on the FIRST failed attempt in a window,
        // not extended on later ones. Left alone, the lockout duration
        // actually shown once the cap is hit is "60s minus however long
        // the attempts took," not a full 60s. The instant this hit is the
        // one that trips the cap, force the timer to a fresh full window
        // starting now, so the countdown always reads the full amount,
        // not some already-decayed number.
        if ($hits >= $this->maxAttempts) {
            Cache::put(
                $throttleKey . ':timer',
                now()->addSeconds($this->decaySeconds)->getTimestamp(),
                $this->decaySeconds
            );
        }

        return $hits;
    }
}
