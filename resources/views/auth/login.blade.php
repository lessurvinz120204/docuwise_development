<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Sign In — DocTrack</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-primary-950 font-sans">
<div class="min-h-full flex items-center justify-center px-4 relative overflow-hidden">
    {{-- Soft ambient glow behind the card — restrained, not a flashy hero,
         just enough depth so the dark background doesn't read as flat. --}}
    <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_60%_50%_at_50%_0%,theme(colors.primary.700/0.35),transparent)]"></div>

    <div class="w-full max-w-sm relative">
        <div class="text-center mb-8">
            <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-primary-400 to-primary-600 mx-auto flex items-center justify-center font-bold text-white text-2xl shadow-elevated ring-1 ring-white/20">D</div>
            <h1 class="mt-5 text-xl font-semibold text-white tracking-tight">Document Classification &amp; Tracking</h1>
            <p class="text-sm text-primary-300 mt-1.5">UJF Corporation — Internal System</p>
        </div>

        <div class="bg-white rounded-2xl shadow-elevated p-8 ring-1 ring-black/5">
            @if(session('status'))
                <div class="mb-5 rounded-xl bg-approved-50 border border-approved-500/25 text-approved-700 px-4 py-3 text-sm">
                    {{ session('status') }}
                </div>
            @endif

            {{-- Also the target for "Get Code" AJAX errors/success — see
                 the script below, which reuses this exact box instead of
                 rendering its own second message area. --}}
            <div id="login-message"
                @if(session('login_retry_after')) data-retry-after="{{ session('login_retry_after') }}" @endif
                class="{{ session('login_retry_after') || $errors->any() ? '' : 'hidden' }} mb-5 rounded-xl px-4 py-3 text-sm
                    {{ session('login_retry_after') ? 'bg-rejected-50 border border-rejected-500/25 text-rejected-700' : (str_contains($errors->first(), 'attempt') ? 'bg-processing-50 border border-processing-500/25 text-processing-700' : 'bg-rejected-50 border border-rejected-500/25 text-rejected-700') }}">
                @if(session('login_retry_after'))
                    Too many login attempts. Try again in <span id="login-throttle-seconds" class="font-semibold">{{ session('login_retry_after') }}</span> second(s).
                @else
                    {{ $errors->first() }}
                @endif
            </div>

            <form method="POST" action="{{ route('login.attempt') }}" class="space-y-5">
                @csrf
                <div>
                    <label for="email" class="block text-sm font-medium text-surface-700 mb-1.5">Email</label>
                    {{-- type="text", not "email" — deliberately: browser-native
                         email validation intercepts an invalid value BEFORE the
                         form ever submits, showing its own small tooltip instead
                         of this app's error banner — easy to miss and
                         inconsistent with every other validation error here.
                         Letting the server's own 'email' rule catch it instead
                         guarantees one consistent, visible error every time. --}}
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-surface-400">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0-.621.504-1.125 1.125-1.125h17.25c.621 0 1.125.504 1.125 1.125v10.5c0 .621-.504 1.125-1.125 1.125H3.375A1.125 1.125 0 012.25 17.25V6.75z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.586 6.343l9 6.3a.75.75 0 00.828 0l9-6.3" />
                            </svg>
                        </span>
                        <input id="email" name="email" type="text" inputmode="email" required autofocus value="{{ old('email') }}"
                            class="w-full rounded-lg border-surface-300 focus:border-primary-500 focus:ring-primary-500 text-sm pl-10 pr-3.5 py-2.5">
                    </div>
                </div>
                <div>
                    <div class="flex items-center justify-between mb-1.5">
                        <label for="password" class="block text-sm font-medium text-surface-700">Password</label>
                        <a href="{{ route('password.request') }}" class="text-xs font-medium text-primary-700 hover:underline">Forgot password?</a>
                    </div>
                    <x-password-input id="password" required />
                </div>

                <div>
                    <label for="code" class="block text-sm font-medium text-surface-700 mb-1.5">Verification Code or Backup Code</label>
                    {{-- One field for both — the backend already tries the
                         emailed code first, then falls back to a Sign In
                         Backup Code automatically (see AuthController::
                         login()), so there's nothing for the UI to switch
                         between. A backup code works immediately, with no
                         need to ever click Get Code — deliberately not
                         gated behind any "email is broken" detection (see
                         User::generateBackupCodes()' docblock): a full
                         inbox, a spam filter, no signal right then — too
                         many reasons the emailed code might not arrive to
                         make this conditional on one specific cause. --}}
                    <div class="flex items-center rounded-full ring-1 ring-inset ring-surface-300 focus-within:ring-2 focus-within:ring-primary-500 bg-white overflow-hidden">
                        <span class="pl-4 pr-2 text-primary-600 shrink-0">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75l1.5 1.5 3.75-3.75M12 3l7.5 3v5.25c0 4.83-3.21 9.15-7.5 10.5-4.29-1.35-7.5-5.67-7.5-10.5V6l7.5-3z" />
                            </svg>
                        </span>
                        <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required
                            placeholder="Enter your 6-digit code or backup code"
                            class="min-w-0 flex-1 border-0 bg-transparent focus:ring-0 text-sm py-3 pr-2 tracking-[0.2em] font-semibold placeholder:font-normal placeholder:tracking-normal placeholder:text-surface-400">
                        <button type="button" id="get-code-btn"
                            class="shrink-0 m-1.5 inline-flex items-center justify-center rounded-full bg-gradient-to-b from-primary-600 to-primary-700 hover:from-primary-700 hover:to-primary-800 text-white text-xs font-semibold px-4 py-2 transition-all disabled:opacity-60 disabled:cursor-not-allowed whitespace-nowrap">
                            Get Code
                        </button>
                    </div>
                </div>

                <button type="submit" id="login-submit"
                    class="w-full bg-gradient-to-b from-primary-600 to-primary-700 hover:from-primary-700 hover:to-primary-800 text-white font-medium text-sm py-2.5 rounded-lg shadow-sm transition-all disabled:opacity-50 disabled:cursor-not-allowed">
                    Sign In
                </button>
            </form>
        </div>
        <p class="text-center text-xs text-primary-400 mt-6">Access is role-restricted. Contact your Administrator for an account.</p>
    </div>
</div>
<script>
    (function () {
        const messageBox = document.getElementById('login-message');
        const submitBtn = document.getElementById('login-submit');
        const getCodeBtn = document.getElementById('get-code-btn');
        const codeInput = document.getElementById('code');
        const emailInput = document.getElementById('email');
        const passwordInput = document.getElementById('password');
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

        function showMessage(text, tone) {
            const tones = {
                error: ['bg-rejected-50', 'border', 'border-rejected-500/25', 'text-rejected-700'],
                success: ['bg-approved-50', 'border', 'border-approved-500/25', 'text-approved-700'],
                info: ['bg-processing-50', 'border', 'border-processing-500/25', 'text-processing-700'],
            };
            messageBox.className = 'mb-5 rounded-xl px-4 py-3 text-sm ' + tones[tone].join(' ');
            messageBox.textContent = text;
        }

        // Live-ticking countdown for the login LOCKOUT message
        // (AuthController's per-email+IP throttle) — separate from the
        // code-validity countdown on the Get Code button below. The
        // server only sends the seconds remaining as of the response —
        // without this, that number would sit frozen and visibly wrong
        // for however long the user actually waits.
        const throttleRetryAfter = messageBox.dataset.retryAfter;
        if (throttleRetryAfter) {
            let remaining = parseInt(throttleRetryAfter, 10);
            const secondsEl = document.getElementById('login-throttle-seconds');
            submitBtn.disabled = true;
            getCodeBtn.disabled = true;

            const tick = () => {
                remaining -= 1;
                if (remaining <= 0) {
                    showMessage('You can try again now.', 'success');
                    getCodeBtn.disabled = false;
                    clearInterval(timer);
                    return;
                }
                secondsEl.textContent = remaining;
            };

            const timer = setInterval(tick, 1000);
        }

        function formatTime(seconds) {
            const m = Math.floor(seconds / 60);
            const s = seconds % 60;
            return `${m}:${String(s).padStart(2, '0')}`;
        }

        let countdownTimer = null;

        // Only ever touches getCodeBtn now — codeInput/submitBtn are
        // usable from page load, since a Sign In Backup Code never needs
        // Get Code clicked at all. An expired emailed code just prompts a
        // resend; it doesn't lock the form, since a backup code still
        // works regardless of whether the emailed one is still live.
        function startCountdown(seconds) {
            clearInterval(countdownTimer);
            let remaining = seconds;
            getCodeBtn.disabled = true;
            getCodeBtn.textContent = formatTime(remaining);

            countdownTimer = setInterval(() => {
                remaining -= 1;
                if (remaining <= 0) {
                    clearInterval(countdownTimer);
                    getCodeBtn.disabled = false;
                    getCodeBtn.textContent = 'Resend Code';
                    showMessage('Your emailed code expired — click Resend Code for a new one, or enter a backup code instead.', 'error');
                    return;
                }
                getCodeBtn.textContent = formatTime(remaining);
            }, 1000);
        }

        getCodeBtn.addEventListener('click', function () {
            if (!emailInput.value || !passwordInput.value) {
                showMessage('Enter your email and password first.', 'error');
                return;
            }

            getCodeBtn.disabled = true;
            const wasResend = getCodeBtn.textContent.trim() === 'Resend Code';

            fetch('{{ route('login.request-code') }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                },
                body: JSON.stringify({ email: emailInput.value, password: passwordInput.value }),
            })
                .then((res) => res.json().then((data) => ({ status: res.status, data })))
                .then(({ status, data }) => {
                    if (status !== 200) {
                        getCodeBtn.disabled = false;
                        showMessage(data.message || 'Could not send a code.', 'error');
                        return;
                    }
                    showMessage(wasResend ? 'A new code has been sent to your email.' : 'A code has been sent to your email.', 'success');
                    startCountdown(data.expiresIn);
                })
                .catch(() => {
                    getCodeBtn.disabled = false;
                    showMessage('Could not send a code — check your connection and try again.', 'error');
                });
        });
    })();
</script>
</body>
</html>
