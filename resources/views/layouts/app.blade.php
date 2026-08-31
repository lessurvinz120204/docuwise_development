<!DOCTYPE html>
<html lang="en" class="h-full bg-surface-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Document Classification & Tracking System')</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{--
        Business-hours config for the client-side real-remaining ticker
        (see the script block below) — cheap (one small holiday query),
        exposed on every page rather than gated to specific ones so it's
        available wherever a [data-real-remaining] element might render.
        startMinutes/endMinutes let the ticker check "is it business hours
        right now" without re-parsing a time string every second.
    --}}
    @php
        $__workStart = \Carbon\Carbon::parse(config('sla.default_work_start'));
        $__workEnd = \Carbon\Carbon::parse(config('sla.default_work_end'));
    @endphp
    <meta name="business-hours" content="{{ json_encode([
        'startMinutes' => $__workStart->hour * 60 + $__workStart->minute,
        'endMinutes' => $__workEnd->hour * 60 + $__workEnd->minute,
        'workingDays' => config('sla.default_working_days'),
        'holidays' => \App\Models\SlaHoliday::pluck('holiday_date')->map(fn ($d) => \Carbon\Carbon::parse($d)->toDateString())->values(),
    ]) }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full font-sans text-surface-800 antialiased">
<div class="h-screen overflow-hidden">

    {{-- ============ SIDEBAR ============ --}}
    {{-- A fixed off-canvas drawer, not a flex sibling that reflows content —
         the hamburger button toggles a `transform: translateX()` between
         off-screen (-translate-x-full) and on-screen (translate-x-0), with
         a backdrop that dims the page and closes the drawer on tap (see
         app.js). This replaced an earlier width-collapsing flex-sibling
         version that relied on `width` transitions + `overflow-hidden`
         clipping to hide — spec-correct, but unreliable enough across
         mobile browsers that it sometimes wouldn't visibly close at all.
         A transform-based drawer is the standard, robust pattern for this
         and behaves identically at every screen width — same mechanism
         everywhere, just implemented in a way that's actually dependable on
         phones, not only desktop. Starts CLOSED by default (a deliberate
         change from the old default-open behavior) so opening the sidebar
         is always an explicit action, not something already dimming/
         blocking the page the moment you load it. --}}
    <aside id="sidebar" class="fixed inset-y-0 left-0 z-40 w-64 h-full flex flex-col bg-gradient-to-b from-primary-900 to-primary-950 text-primary-100 transform -translate-x-full transition-transform duration-300 ease-in-out">
        <div class="flex items-center justify-between gap-2 px-6 h-16 border-b border-white/10">
            <div class="flex items-center gap-2.5">
                <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-primary-400 to-primary-600 flex items-center justify-center font-bold text-white shadow-sm ring-1 ring-white/20 flex-shrink-0">D</div>
                <span class="font-semibold text-white tracking-tight text-[15px] whitespace-nowrap">DocTrack</span>
            </div>
            <button id="sidebar-close" type="button" class="p-1 rounded-lg text-primary-300 hover:text-white hover:bg-white/5 flex-shrink-0" aria-label="Close menu">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <nav class="flex-1 overflow-y-auto overflow-x-hidden px-3 py-6 space-y-1 text-sm">
            @auth
                @if(auth()->user()->isAdmin())
                    @include('layouts.nav-admin')
                @elseif(auth()->user()->isOriginator())
                    @include('layouts.nav-originator')
                @elseif(auth()->user()->isApprover())
                    @include('layouts.nav-approver')
                @endif
            @endauth
        </nav>

        @auth
        <div class="px-4 py-4 border-t border-white/10">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-full bg-gradient-to-br from-primary-500 to-primary-700 flex items-center justify-center text-white text-sm font-semibold ring-1 ring-white/10 flex-shrink-0">
                    {{ strtoupper(substr(auth()->user()->full_name, 0, 1)) }}
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-white truncate">{{ auth()->user()->full_name }}</p>
                    <p class="text-xs text-primary-300 capitalize">{{ auth()->user()->role }}</p>
                </div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="p-1.5 rounded-lg text-primary-300 hover:text-white hover:bg-white/5" title="Log out">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                        </svg>
                    </button>
                </form>
            </div>
        </div>
        @endauth
    </aside>

    {{-- Dims the page and closes the drawer on tap — only visible/clickable
         while the sidebar is open (see app.js toggling opacity + pointer-events). --}}
    <div id="sidebar-backdrop" class="fixed inset-0 bg-surface-900/50 z-30 opacity-0 pointer-events-none transition-opacity duration-300"></div>

    {{-- ============ MAIN COLUMN ============ --}}
    {{-- No longer a flex sibling reacting to the sidebar's width — the
         sidebar is fixed/out-of-flow now, so this is simply always full
         width. overflow-hidden here (paired with h-screen on the outer
         wrapper) is what makes <main> below the ONLY thing that scrolls
         when a page has a lot of content — this column itself never grows
         past the viewport, so the header stays put regardless of page
         length. --}}
    <div class="h-full flex flex-col overflow-hidden">

        {{-- Top bar --}}
        <header class="flex-shrink-0 z-10 bg-white/85 backdrop-blur-md border-b border-surface-200/80 shadow-[0_1px_0_0_rgb(15_23_42_/_0.02)] h-16 flex items-center px-4 sm:px-8">
            <button id="sidebar-toggle" type="button" class="-ml-1 mr-3 p-2 rounded-lg text-surface-500 hover:bg-surface-100 hover:text-surface-900" aria-label="Toggle menu" aria-expanded="false" aria-controls="sidebar">
                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/>
                </svg>
            </button>
            <h1 class="text-[1.05rem] font-semibold text-surface-900 tracking-tight">@yield('page-title', 'Dashboard')</h1>
            <div class="ml-auto flex items-center gap-4">
                @auth
                    <x-notification-bell />
                    <span class="text-xs px-2.5 py-1 rounded-full bg-primary-50 text-primary-700 ring-1 ring-inset ring-primary-500/15 font-medium capitalize">{{ auth()->user()->role }}</span>
                @endauth
            </div>
        </header>

        <main class="flex-1 overflow-y-auto p-4 sm:p-8 space-y-6">
            @if(session('status'))
                <div class="rounded-xl bg-approved-50 border border-approved-500/25 text-approved-700 px-4 py-3 text-sm font-medium shadow-sm flex items-center gap-2.5 transition-opacity duration-300" role="status">
                    <svg class="w-5 h-5 flex-shrink-0 text-approved-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    {{ session('status') }}
                </div>
            @endif

            @if($errors->any())
                <div class="rounded-xl bg-rejected-50 border border-rejected-500/25 text-rejected-700 px-4 py-3 text-sm shadow-sm" role="alert">
                    <div class="flex items-center gap-2.5 font-medium mb-1">
                        <svg class="w-5 h-5 flex-shrink-0 text-rejected-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
                        Please correct the following:
                    </div>
                    <ul class="list-disc list-inside space-y-0.5 pl-1">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @yield('content')
        </main>
    </div>
</div>
@auth
    <x-document-viewer-modal />
    <x-backup-codes-modal />
    @if(auth()->user()->isAdmin())
        <x-kpi-drilldown-modal />
    @endif

    @if(session('new_backup_codes'))
        {{-- application/json, not a data-attribute — codes never
             round-trip through HTML attribute encoding this way, and
             the value can't terminate early on a stray quote. Shown
             once, on the account holder's own first successful login —
             see AuthController::login()'s new_backup_codes flash. --}}
        <script type="application/json" id="new-backup-codes-data">@json(session('new_backup_codes'))</script>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const codes = JSON.parse(document.getElementById('new-backup-codes-data').textContent);
                openBackupCodesModalWithCodes(
                    'Your Sign In Backup Codes',
                    codes,
                    "Save these now — if you ever lose access, your Administrator can look them up for you, but they'll need your current password to do it."
                );
            });
        </script>
    @endif
@endauth
<script>
    // Live relative-time updater (Feature: SLA countdowns/expiries update in
    // real time without a page refresh). Any element with a
    // data-live-time="<unix seconds>" attribute gets its text kept in sync
    // every second, formatted as "Xh Ym Zs remaining" (or "... ago" once
    // past) so the seconds digit visibly ticks — a coarser "X minutes from
    // now" style wording only changes once a minute, which looks frozen.
    // Optionally pass data-live-urgent-under="<seconds>" to toggle an
    // "urgent" red color once less than that many seconds remain.
    function __docTrackFormatDuration(totalSeconds) {
        const h = Math.floor(totalSeconds / 3600);
        const m = Math.floor((totalSeconds % 3600) / 60);
        const s = totalSeconds % 60;

        if (h > 0) return `${h}h ${m}m ${s}s`;
        if (m > 0) return `${m}m ${s}s`;
        return `${s}s`;
    }

    function __docTrackRelativeTime(unixSeconds) {
        const diffMs = (unixSeconds * 1000) - Date.now();
        const isFuture = diffMs >= 0;
        const totalSeconds = Math.max(0, Math.round(Math.abs(diffMs) / 1000));
        const duration = __docTrackFormatDuration(totalSeconds);

        return isFuture ? `${duration} remaining` : `${duration} ago`;
    }

    function __docTrackUpdateLiveTimes() {
        document.querySelectorAll('[data-live-time]').forEach((el) => {
            const ts = parseInt(el.getAttribute('data-live-time'), 10);
            if (!ts) return;

            el.textContent = __docTrackRelativeTime(ts);

            const urgentUnder = el.getAttribute('data-live-urgent-under');
            if (urgentUnder !== null) {
                const secondsRemaining = ts - Math.floor(Date.now() / 1000);
                el.classList.toggle('text-rejected-700', secondsRemaining < parseInt(urgentUnder, 10));
                el.classList.toggle('text-surface-600', secondsRemaining >= parseInt(urgentUnder, 10));
            }
        });
    }

    document.addEventListener('DOMContentLoaded', __docTrackUpdateLiveTimes);
    setInterval(__docTrackUpdateLiveTimes, 1000);

    // Real (business-hours-aware) countdown ticker — a sibling to the
    // generic wall-clock ticker above, not a replacement for it (that one
    // is used elsewhere for plain elapsed/remaining time and shouldn't
    // gain business-hours awareness it was never meant to have). Ticks any
    // [data-real-remaining="<seconds>"] element down by 1 every second
    // ONLY while currently inside a working window, freezing it
    // otherwise — matching the "⏸ Paused" note shown next to it. Starting
    // value comes from the server (DocumentAssignment::realSecondsRemaining());
    // this only ever counts down from there, self-correcting on the next
    // page refresh/live swap rather than trying to replicate the server's
    // full walk-forward business-time algorithm in JS.
    const __businessHoursConfig = JSON.parse(document.querySelector('meta[name="business-hours"]')?.content || 'null');

    // Manila is a fixed UTC+8 with no DST (see BusinessHoursService's
    // class docblock) — reconstructing wall-clock time from a fixed
    // offset means this stays correct regardless of the visitor's own
    // browser/OS timezone, instead of trusting `new Date()`'s local time.
    const MANILA_OFFSET_MS = 8 * 60 * 60 * 1000;

    function __isWithinBusinessHoursNow() {
        if (!__businessHoursConfig) return true; // fail open — never wrongly freeze if config didn't load

        const manila = new Date(Date.now() + MANILA_OFFSET_MS);
        if (!__businessHoursConfig.workingDays.includes(manila.getUTCDay())) return false;

        const dateStr = manila.toISOString().slice(0, 10);
        if (__businessHoursConfig.holidays.includes(dateStr)) return false;

        const nowMinutes = manila.getUTCHours() * 60 + manila.getUTCMinutes();
        return nowMinutes >= __businessHoursConfig.startMinutes && nowMinutes < __businessHoursConfig.endMinutes;
    }

    function __docTrackUpdateRealRemaining() {
        const withinHours = __isWithinBusinessHoursNow();

        document.querySelectorAll('[data-real-remaining]').forEach((el) => {
            let remaining = parseInt(el.dataset.realRemaining, 10);
            if (isNaN(remaining)) return;

            if (remaining > 0 && withinHours) {
                remaining -= 1;
                el.dataset.realRemaining = remaining;
            }

            el.textContent = remaining > 0 ? `(${__docTrackFormatDuration(remaining)} remaining)` : '(expired)';

            const urgentUnder = el.getAttribute('data-live-urgent-under');
            if (urgentUnder !== null) {
                el.classList.toggle('text-rejected-700', remaining < parseInt(urgentUnder, 10));
                el.classList.toggle('text-surface-600', remaining >= parseInt(urgentUnder, 10));
            }
        });
    }

    document.addEventListener('DOMContentLoaded', __docTrackUpdateRealRemaining);
    setInterval(__docTrackUpdateRealRemaining, 1000);

    // Analytics "Now" line ticker — walks the vertical marker on the
    // Admin Dashboard's Day-tab chart (admin/partials/analytics-panel.blade.php)
    // forward in real time, live-clock label included. Lives here (not in
    // that panel's own script) for the same reason __docTrackUpdateRealRemaining
    // does: one global, DOM-driven ticker that's a harmless no-op wherever
    // its target element doesn't currently exist, rather than something
    // that needs re-wiring every time the panel gets AJAX-swapped in.
    // Reuses MANILA_OFFSET_MS above for the same "correct regardless of
    // the visitor's own browser timezone" reason.
    //
    // Snapped to the nearest 15-minute mark (:00/:15/:30/:45), not the
    // exact minute — the chart maps a full 24-hour day across roughly 950
    // SVG units, so minute-by-minute movement was only a fraction of a
    // pixel per update and read as barely moving at all. Stepping in
    // 15-minute jumps makes each move ~4x bigger and genuinely visible
    // (11:15 -> 11:30 -> 11:45 -> 12:00), holding still between marks
    // instead of creeping continuously.
    //
    // Ticks every 1s (matching __docTrackUpdateRealRemaining above), not
    // on some longer delay — the function itself is cheap, and the point
    // of checking often is to catch the moment a 15-minute mark actually
    // passes as close to real time as possible, rather than the step
    // lagging behind by however long the tick interval is.
    //
    // data-step-x is the EXACT spacing analytics-panel.blade.php uses
    // between its 24 hour-points (x = AXIS_W + index * stepX). Position is
    // computed as a fractional INDEX (hour + minutes/60) times that same
    // stepX — not an independent minutes-into-day/1440 fraction of the
    // total width. Those two scales don't match (24 points is 23 gaps,
    // not 24), which used to drift the line away from where the hour
    // points actually sit. Reusing the identical stepX value guarantees
    // this can only ever land on/between the real points, never off from
    // them.
    function __docTrackUpdateAnalyticsNowLine() {
        const marker = document.querySelector('.analytics-now-marker');
        if (!marker) return;

        const plotStart = parseFloat(marker.dataset.plotStart);
        const stepX = parseFloat(marker.dataset.stepX);
        if (isNaN(plotStart) || isNaN(stepX)) return;

        const manila = new Date(Date.now() + MANILA_OFFSET_MS);
        const snappedMinutes = Math.floor(manila.getUTCMinutes() / 15) * 15;
        const fractionalIndex = manila.getUTCHours() + (snappedMinutes / 60);
        const x = plotStart + fractionalIndex * stepX;

        const line = marker.querySelector('.analytics-now-line');
        if (line) {
            line.setAttribute('x1', x);
            line.setAttribute('x2', x);
        }

        const label = marker.querySelector('.analytics-now-label');
        if (label) {
            label.setAttribute('x', x);
            const hours12 = manila.getUTCHours() % 12 || 12;
            const ampm = manila.getUTCHours() < 12 ? 'AM' : 'PM';
            const minutes = String(snappedMinutes).padStart(2, '0');
            label.textContent = `${hours12}:${minutes} ${ampm}`;
        }
    }

    document.addEventListener('DOMContentLoaded', __docTrackUpdateAnalyticsNowLine);
    setInterval(__docTrackUpdateAnalyticsNowLine, 1000);
</script>
@stack('scripts')
</body>
</html>