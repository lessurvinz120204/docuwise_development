@props(['document'])

@php
    $steps = [
        'processing' => 'Submitted',
        'classified_validated' => 'Classified & Validated',
        'approved' => 'Approved',
    ];
    $order = array_keys($steps);

    $status = $document->global_status;
    $isRejected = $status === 'rejected';
    // A security-blocked document never reached classification at all —
    // it stops right at "Submitted" (index 0), not "Classified &
    // Validated" (index 1) the way an ordinary human rejection does, and
    // gets its own caption below rather than the generic rejection one.
    $isSecurityBlocked = (bool) $document->is_security_blocked;

    // Normalize auto_approved -> approved for stepper positioning.
    $effective = in_array($status, ['approved', 'auto_approved']) ? 'approved' : $status;
    $found = array_search($effective, $order);
    $currentIndex = $isSecurityBlocked ? 0 : ($isRejected ? 1 : ($found === false ? 0 : $found));
@endphp

{{-- overflow-x-auto is the fallback for very narrow phones — the three
     whitespace-nowrap step labels don't shrink below their text width, so
     rather than let them force the whole page to scroll horizontally, only
     this strip scrolls if it ever doesn't fit. --}}
<div class="overflow-x-auto">
<div class="flex items-center w-full min-w-max sm:min-w-0">
    @foreach($order as $i => $key)
        @php
            $isCurrent = $i === $currentIndex;
            // A node turns green the moment the document has reached OR
            // passed it — including the node it's sitting at right now.
            // "Classified & Validated" describes something the system
            // already finished, not something still in progress, so it
            // shouldn't render any differently than "Submitted" once it's
            // true. The rejected node is the one exception: it renders red
            // instead, to flag where things actually stopped rather than
            // implying it completed normally.
            $isComplete = $i < $currentIndex || ($isCurrent && !$isRejected);
        @endphp
        <div class="flex items-center {{ $i < count($order) - 1 ? 'flex-1' : '' }}">
            <div class="flex flex-col items-center gap-1.5">
                <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold ring-2 transition-shadow
                    @if($isComplete) bg-gradient-to-br from-approved-500 to-approved-600 text-white ring-approved-500 shadow-sm shadow-approved-500/30
                    @elseif($isCurrent && $isRejected) bg-gradient-to-br from-rejected-500 to-rejected-600 text-white ring-rejected-500 shadow-sm shadow-rejected-500/30
                    @else bg-surface-100 text-surface-400 ring-surface-200 @endif">
                    @if($isComplete)
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                    @else
                        {{ $i + 1 }}
                    @endif
                </div>
                <span class="text-[11px] font-medium text-surface-500 whitespace-nowrap tracking-wide">{{ $steps[$key] }}</span>
            </div>
            @if($i < count($order) - 1)
                @php
                    // The "actively working toward the next milestone"
                    // signal lives on the connecting LINE, not the node —
                    // amber specifically for the segment leading away from
                    // wherever the document currently sits, so a finished
                    // milestone (the node) never looks like it's still
                    // pending while the genuinely pending part (what comes
                    // after it) does.
                    $isLineComplete = $i < $currentIndex;
                    $isLineProcessing = $isCurrent && !$isRejected;
                @endphp
                <div class="flex-1 h-0.5 mx-2 rounded-full
                    @if($isLineComplete) bg-approved-500
                    @elseif($isLineProcessing) bg-processing-500
                    @else bg-surface-200 @endif"></div>
            @endif
        </div>
    @endforeach
</div>
</div>

@if($isSecurityBlocked)
    <p class="mt-2 text-xs font-medium text-rejected-700">This document was blocked by an automated security scan before it ever reached review.</p>
@elseif($isRejected)
    <p class="mt-2 text-xs font-medium text-rejected-700">This document was rejected during review.</p>
@endif
