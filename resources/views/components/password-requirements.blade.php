{{--
    Live password-strength checklist — turns each line green the instant
    that rule is met, so the server's error message (still enforced
    identically at submit — see PasswordRule::min(8)->mixedCase()->
    numbers()->uncompromised() in AdminController::storeUser()/
    AuthController::resetPassword()) is a fallback, not the only signal.
    One shared component + one delegated listener in app.js (matching how
    the password show/hide toggle already works), wired to any input via
    data-password-requirements-for="<input id>" — no per-page JS needed.

    Four of the five rules (length/uppercase/lowercase/number) are pure
    pattern checks and update on every keystroke. The 5th — "not a known
    leaked password" — can't be judged locally; it needs the same free,
    keyless Pwned Passwords API the server-side uncompromised() rule
    already calls, so it updates a moment after typing pauses instead of
    live, and only ever sends a partial hash prefix, never the password
    itself (see the k-anonymity comment in app.js).
--}}
@props(['for'])

<ul data-password-requirements-for="{{ $for }}" class="mt-2 space-y-1 text-xs">
    @foreach([
        'length' => 'At least 8 characters',
        'uppercase' => 'One uppercase letter',
        'lowercase' => 'One lowercase letter',
        'number' => 'One number',
    ] as $rule => $label)
        <li data-rule="{{ $rule }}" class="flex items-center gap-1.5 text-surface-400 transition-colors">
            <svg class="w-3.5 h-3.5 shrink-0" data-icon-unmet fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="9" />
            </svg>
            <svg class="w-3.5 h-3.5 shrink-0 hidden" data-icon-met fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75l1.5 1.5 3.75-3.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <span>{{ $label }}</span>
        </li>
    @endforeach
    <li data-rule="uncompromised" class="flex items-center gap-1.5 text-surface-400 transition-colors">
        <svg class="w-3.5 h-3.5 shrink-0" data-icon-unmet fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="9" />
        </svg>
        <svg class="w-3.5 h-3.5 shrink-0 hidden" data-icon-met fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75l1.5 1.5 3.75-3.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <svg class="w-3.5 h-3.5 shrink-0 hidden" data-icon-bad fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 9.75l4.5 4.5m0-4.5l-4.5 4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <span data-status-text>Not a known leaked password</span>
    </li>
</ul>
