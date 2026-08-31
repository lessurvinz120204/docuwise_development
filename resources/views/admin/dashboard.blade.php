@extends('layouts.app')
@section('title', 'Control Center')
@section('page-title', 'Admin Control Center')

@section('content')
<div class="space-y-6">
    <div id="admin-overview" data-poll-url="{{ route('admin.dashboard.poll') }}" data-refresh-url="{{ route('admin.dashboard.refresh') }}">
        @include('admin.partials.overview')
    </div>
</div>

<script>
    // Live-updates the KPI cards + SLA alerts without a full page reload —
    // instant via Reverb (see startLiveChannel in resources/js/app.js) the
    // moment any document changes status anywhere in the system; the slow
    // poll behind it is only a fallback in case the WebSocket connection
    // is down. Not scoped to one user's channel — every admin shares the
    // same 'admin-dashboard' channel, since admins see all documents.
    //
    // Wrapped in DOMContentLoaded, not a bare IIFE — see the matching
    // comment in approver/dashboard.blade.php for why: this plain inline
    // script would otherwise run before app.js's deferred module script
    // has defined startLiveChannel/startLivePoll, throw immediately, and
    // silently never wire anything up.
    // Recent Activity (the last section on the page, now full width — see
    // overview.blade.php) is sized to exactly fill whatever space is left
    // below it, the same technique already proven on the Document Tracker
    // page: a fixed max-height guess didn't reliably fit this page (KPI
    // row + Analytics + SLA/Category above it varies in height), so this
    // measures live against <main>'s own bottom edge instead — the PAGE
    // never scrolls, only this one table does internally if it runs long.
    function sizeRecentActivity() {
        const scrollEl = document.getElementById('admin-recent-activity-scroll');
        const mainEl = document.querySelector('main');
        if (!scrollEl || !mainEl) return;

        const mainPaddingBottom = parseFloat(getComputedStyle(mainEl).paddingBottom) || 0;
        const available = mainEl.getBoundingClientRect().bottom - mainPaddingBottom - scrollEl.getBoundingClientRect().top;
        // Floor is deliberately low (not a "comfortable minimum" like
        // Document Tracker's 200px) — this row sits below a taller,
        // already-compacted Analytics/SLA/Category row, so there just
        // isn't much slack to spare on a short screen. A shorter-than-
        // ideal scroll area here is still strictly better than forcing
        // the whole page to overflow, which a higher floor did exactly
        // that on a real 900px-tall viewport (measured directly, not
        // guessed) — the floor must never win an argument with the
        // actual available space.
        scrollEl.style.maxHeight = Math.max(available - 8, 90) + 'px';
    }

    document.addEventListener('DOMContentLoaded', function () {
        const overviewEl = document.getElementById('admin-overview');
        if (!overviewEl) return;

        sizeRecentActivity();
        window.addEventListener('resize', sizeRecentActivity);

        const opts = {
            refreshUrl: overviewEl.dataset.refreshUrl,
            target: overviewEl,
        };

        startLiveChannel('admin-dashboard', '.document.status-changed', opts);
        // Covers everything else on this page — logins, uploads, decisions,
        // SLA escalations, approver availability toggles — that isn't a
        // document status change but still needs Recent Activity/Analytics/
        // etc. to update live (see AdminActivityLogged).
        startLiveChannel('admin-dashboard', '.admin.activity-logged', opts);

        // The ONE reusable Analytics chart/KPI/table panel: the admin's
        // currently-selected Day/Week/Month/Year granularity + date filter
        // is tracked here in JS and fetched from the server on demand —
        // never four pre-rendered panels toggled by CSS. Seeded from
        // whatever the initial dashboard() load already rendered so
        // switching tabs immediately doesn't refetch the default view first.
        let analyticsGranularity = overviewEl.querySelector('.analytics-panel-content')?.dataset.granularity || 'day';
        let analyticsAsOf = overviewEl.querySelector('.analytics-panel-content')?.dataset.asOf || null;

        function loadAnalyticsPanel() {
            const panelEl = overviewEl.querySelector('#analytics-panel');
            if (!panelEl) return;

            const url = new URL(panelEl.dataset.refreshUrl, window.location.origin);
            url.searchParams.set('granularity', analyticsGranularity);
            if (analyticsAsOf) url.searchParams.set('as_of', analyticsAsOf);

            fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then((r) => r.text())
                .then((html) => { panelEl.innerHTML = html; })
                .catch(() => {});
        }

        // Tab clicks — delegated on the stable #admin-overview wrapper
        // rather than bound directly to the tab buttons, because those
        // buttons live inside admin/partials/overview.blade.php, which gets
        // replaced wholesale on every live refresh below. A listener bound
        // to the old (now-removed) buttons would silently stop working
        // after the first live update; delegation on the wrapper that never
        // gets replaced keeps working across any number of swaps.
        overviewEl.addEventListener('click', function (e) {
            const btn = e.target.closest('.analytics-tab-btn');
            if (!btn || !overviewEl.contains(btn)) return;

            analyticsGranularity = btn.dataset.analyticsTab;
            overviewEl.querySelectorAll('.analytics-tab-btn').forEach((b) => {
                b.classList.toggle('bg-primary-700', b === btn);
                b.classList.toggle('text-white', b === btn);
                b.classList.toggle('text-surface-600', b !== btn);
            });
            loadAnalyticsPanel();
        });

        overviewEl.addEventListener('change', function (e) {
            const input = e.target.closest('#analytics-date-filter');
            if (!input || !overviewEl.contains(input)) return;

            analyticsAsOf = input.value || null;
            loadAnalyticsPanel();
        });

        // Chart hover: moves the crosshair + the three series' markers to
        // whichever period is nearest the cursor, and updates the single
        // date/value readout above the chart to match — replaces a
        // permanent row of x-axis dates with exactly one date shown at a
        // time. Delegated on the wrapper (not bound to the <svg> directly)
        // for the same reason as the tab-click listener above: the chart
        // itself gets replaced wholesale on every panel swap.
        function analyticsPointAt(svg, clientX) {
            const points = JSON.parse(svg.dataset.points || '[]');
            if (!points.length) return null;
            const rect = svg.getBoundingClientRect();
            const vbWidth = svg.viewBox.baseVal.width || rect.width;
            const relX = ((clientX - rect.left) / rect.width) * vbWidth;

            let nearest = points[0];
            let minDist = Math.abs(points[0].x - relX);
            for (const p of points) {
                const dist = Math.abs(p.x - relX);
                if (dist < minDist) { minDist = dist; nearest = p; }
            }
            return nearest;
        }

        function applyAnalyticsPoint(svg, point) {
            if (!point) return;

            const crosshair = svg.querySelector('.analytics-crosshair');
            if (crosshair) { crosshair.setAttribute('x1', point.x); crosshair.setAttribute('x2', point.x); }

            ['uploaded', 'approved', 'rejected'].forEach((key) => {
                const dot = svg.querySelector(`.analytics-hover-dot-${key}`);
                if (dot) { dot.setAttribute('cx', point.x); dot.setAttribute('cy', point[`${key}Y`]); }
            });

            const readout = svg.closest('.analytics-panel-content')?.querySelector('[data-analytics-readout]');
            if (!readout) return;
            readout.querySelector('[data-readout-date]').textContent = point.bucket;
            readout.querySelector('[data-readout-uploaded]').textContent = point.uploaded;
            readout.querySelector('[data-readout-approved]').textContent = point.approved;
            readout.querySelector('[data-readout-rejected]').textContent = point.rejected;
        }

        overviewEl.addEventListener('mousemove', function (e) {
            const svg = e.target.closest('.analytics-chart-svg');
            if (!svg) return;
            applyAnalyticsPoint(svg, analyticsPointAt(svg, e.clientX));
        });

        // mouseleave doesn't bubble, so delegation uses mouseout + a
        // relatedTarget check instead — resets to the most recent period
        // once the cursor genuinely leaves the chart (not just moving
        // between child elements within it).
        overviewEl.addEventListener('mouseout', function (e) {
            const svg = e.target.closest('.analytics-chart-svg');
            if (!svg || svg.contains(e.relatedTarget)) return;
            const points = JSON.parse(svg.dataset.points || '[]');
            applyAnalyticsPoint(svg, points[points.length - 1]);
        });

        // Every live refresh above swaps #admin-overview's ENTIRE contents
        // wholesale, including the analytics panel — and overviewRefresh()
        // deliberately doesn't compute panel data (see dashboardExtras()'s
        // docblock), so right after any such swap the panel comes back
        // empty. Re-fetch it here with whatever granularity/date the admin
        // had selected, so a background live update never silently resets
        // their filter back to the default. Recent Activity's height also
        // needs recomputing after every such swap, for the same reason
        // Document Tracker's does — the new content above it can render at
        // a different height than before.
        opts.onSwap = function (signalData) {
            loadAnalyticsPanel(signalData);
            sizeRecentActivity();
        };

        startLivePoll({ ...opts, pollUrl: overviewEl.dataset.pollUrl });
    });
</script>
@endsection
