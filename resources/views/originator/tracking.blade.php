@extends('layouts.app')
@section('title', 'Track Document')
@section('page-title', 'Document Tracking')

@section('content')
<div id="tracking-content"
    data-document-id="{{ $document->document_id }}"
    data-originator-id="{{ $document->originator_id }}"
    data-poll-url="{{ route('originator.documents.trackingPoll', $document) }}"
    data-refresh-url="{{ route('originator.documents.trackingRefresh', $document) }}">
    @include('originator.partials.tracking-content')
</div>

<script>
    // Live-updates this document's status/stages/audit trail without a
    // full page reload — instant via Reverb the moment any stage on THIS
    // document is decided or its overall status changes (not just full
    // approval/rejection — a single stage being approved mid-pipeline
    // updates the Approval Stages list here too); the slow poll behind it
    // is only a fallback in case the WebSocket connection is down. See
    // startLiveChannel()/startLivePoll() in resources/js/app.js, and the
    // matching comment in approver/dashboard.blade.php for why this is
    // wrapped in DOMContentLoaded rather than a bare IIFE.
    // Document Tracker's own scroll area (see tracking-content.blade.php)
    // is sized to exactly fill the space left over below the document
    // header card, so a long tracker scrolls internally instead of pushing
    // <main> — this app's real scroll container, see layouts/app.blade.php
    // — into scrolling the whole page. Measured live against <main>'s own
    // bottom edge rather than a fixed calc(), since the header card above
    // it varies in height (version badge, resubmit form, Imported/
    // Superseded notices, ...) — a fixed number would only be correct for
    // whichever card height happened to be on screen when it was written.
    function sizeDocumentTracker() {
        const scrollEl = document.getElementById('document-tracker-scroll');
        const mainEl = document.querySelector('main');
        if (!scrollEl || !mainEl) return;

        // <main>'s own bottom padding (p-4 sm:p-8) sits between its
        // content and its measured bottom edge — read live via
        // getComputedStyle rather than hardcoded, so this stays correct if
        // that padding class ever changes, instead of quietly drifting out
        // of sync again the way a flat buffer alone previously did (it
        // left the tracker ~24px too tall, just enough to force <main>
        // itself into scrolling on top of the tracker's own internal one).
        const mainPaddingBottom = parseFloat(getComputedStyle(mainEl).paddingBottom) || 0;
        const available = mainEl.getBoundingClientRect().bottom - mainPaddingBottom - scrollEl.getBoundingClientRect().top;
        // Floor guard so a very tall header card never collapses this to
        // something unusably short.
        scrollEl.style.maxHeight = Math.max(available - 8, 200) + 'px';
    }

    document.addEventListener('DOMContentLoaded', function () {
        const contentEl = document.getElementById('tracking-content');
        if (!contentEl) return;

        sizeDocumentTracker();
        window.addEventListener('resize', sizeDocumentTracker);

        const thisDocumentId = parseInt(contentEl.dataset.documentId, 10);

        const opts = {
            refreshUrl: contentEl.dataset.refreshUrl,
            target: contentEl,
            // The originator's channel carries events for ALL of their
            // documents, not just this one — only react when the event is
            // actually about the document this page is showing.
            filter: (data) => data.document_id === thisDocumentId,
            // The live swap replaces #tracking-content's whole innerHTML
            // (new header card content, new tracker rows) — re-measure
            // afterward, not just once on initial load.
            onSwap: sizeDocumentTracker,
        };

        // Subscribed to the document's actual owner's channel, not the
        // current viewer's own id — for the originator viewing their own
        // document these are the same person, but an Admin (or anyone else
        // permitted onto this page) viewing someone ELSE's document has a
        // different id from the owner, and the server only ever broadcasts
        // document.status-changed on the owner's channel (see
        // DocumentStatusChanged::broadcastOn()). Subscribing to the
        // viewer's own id there would silently never receive anything,
        // leaving that viewer stuck on the slow poll fallback only.
        startLiveChannel(`originator.${contentEl.dataset.originatorId}`, '.document.status-changed', opts);
        startLivePoll({ ...opts, pollUrl: contentEl.dataset.pollUrl });

        // Heads-up only, not authoritative — same check as the main
        // upload form's (see originator/dashboard.blade.php's matching
        // comment for the full reasoning). Delegated on #tracking-content
        // — the stable wrapper that survives every live swap above —
        // rather than bound directly to the input, which gets replaced
        // wholesale on every swap along with the rest of this fragment.
        const businessHoursConfig = JSON.parse(document.querySelector('meta[name="business-hours"]')?.content || 'null');

        function isWithinWorkingHours(date) {
            if (!businessHoursConfig || isNaN(date.getTime())) return true;
            if (!businessHoursConfig.workingDays.includes(date.getDay())) return false;

            const y = date.getFullYear();
            const m = String(date.getMonth() + 1).padStart(2, '0');
            const d = String(date.getDate()).padStart(2, '0');
            if (businessHoursConfig.holidays.includes(`${y}-${m}-${d}`)) return false;

            const minutes = date.getHours() * 60 + date.getMinutes();
            return minutes >= businessHoursConfig.startMinutes && minutes < businessHoursConfig.endMinutes;
        }

        contentEl.addEventListener('change', function (e) {
            const input = e.target.closest('#resubmit-due-date');
            if (!input) return;
            const warning = contentEl.querySelector('#resubmit-due-date-warning');
            if (!warning) return;
            const valid = !input.value || isWithinWorkingHours(new Date(input.value));
            warning.classList.toggle('hidden', valid);
        });
    });
</script>
@endsection
