{{--
    The whole tracking page body — split out from tracking.blade.php so the
    same markup can be rendered two ways: a normal full page load, and a
    fragment returned by DocumentController::trackingRefresh() for the
    live-poll JS to swap in place (see tracking.blade.php) without a full
    page reload.

    Layout (Feature: no more scrolling all the way down just to see the
    Document Tracker): a 2-column grid on wide screens — the document header +
    Document Tracker stacked in the left half (Document Tracker sized by JS
    to exactly fill the remaining space below the header card, see
    tracking.blade.php's sizeDocumentTracker() — a fixed CSS calc() can't do
    this correctly since the header card's own height varies with its
    content: version badge, resubmit form, Imported/Superseded notices,
    etc.), Approval Stages alone filling the right half. Stacks back to one
    column on narrow/mobile. Shared by both the Originator's own tracking
    page and Admin's Document Tracking module, since both route through
    DocumentController::show() to this same partial.
--}}
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
    <div class="space-y-6 flex flex-col">
        <div class="bg-white rounded-xl shadow-card border border-surface-200 p-6">
            <div class="flex items-start justify-between mb-6">
                <div>
                    <div class="flex items-center gap-2">
                        <h2 class="text-base font-semibold text-surface-900">{{ $document->title }}</h2>
                        @if($document->version_number > 1)
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold bg-indigo-50 text-indigo-700">v{{ $document->version_number }}</span>
                        @endif
                        @if($document->is_legacy_import)
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold bg-processing-50 text-processing-700">Imported</span>
                        @endif
                        @if($document->requires_printing && in_array($document->global_status, ['approved', 'auto_approved']))
                            @if(auth()->user()->isOriginator())
                                <button type="button"
                                    onclick="openDocumentViewer('{{ route('documents.file', $document) }}', '{{ $document->mime_type }}', '{{ addslashes($document->original_filename ?? $document->title) }}', {{ $document->document_id }}, true)"
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold bg-indigo-50 text-indigo-700 hover:bg-indigo-100 transition-colors cursor-pointer">
                                    🖨 Print Required
                                </button>
                            @else
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold bg-indigo-50 text-indigo-700">🖨 Print Required</span>
                            @endif
                        @endif
                    </div>
                    @if($document->is_legacy_import)
                        <p class="text-xs text-processing-700 mt-1">
                            Imported directly by an administrator — not classified, validated, or peer-reviewed through the normal approval workflow.
                        </p>
                    @endif
                    @if($document->previousVersion)
                        <p class="text-xs text-surface-500 mt-1">
                            Resubmission of
                            <a href="{{ route('originator.documents.show', $document->previousVersion) }}" class="text-primary-700 hover:underline font-medium">
                                "{{ $document->previousVersion->title }}" (v{{ $document->previousVersion->version_number }})
                            </a>, which was rejected.
                        </p>
                    @endif
                    @if($document->nextVersion)
                        <p class="text-xs text-rejected-700 mt-1">
                            Superseded by
                            <a href="{{ route('originator.documents.show', $document->nextVersion) }}" class="hover:underline font-medium">
                                a resubmitted version (v{{ $document->nextVersion->version_number }})
                            </a> — that one reflects the current state of this request.
                        </p>
                    @endif
                    <p class="text-xs text-surface-500 mt-1">
                        Category: <span class="font-medium text-surface-700">{{ $document->ml_category ?? 'Unclassified' }}</span>
                        @if($document->ml_rechecked_at)
                            &middot; <span class="text-surface-400">Recheck Confidence: {{ $document->ml_confidence }}% &rarr; {{ $document->ml_recheck_confidence }}%</span>
                        @elseif($document->ml_confidence)
                            &middot; Confidence: {{ $document->ml_confidence }}%
                        @endif
                        @if($document->readability_score !== null)
                            &middot; Readability: {{ $document->readability_score }}%
                        @endif
                        @if($document->used_ocr_fallback)
                            &middot; <span class="text-processing-700">OCR fallback used</span>
                        @endif
                        &middot;
                        <button type="button"
                            onclick="openDocumentViewer('{{ route('documents.file', $document) }}', '{{ $document->mime_type }}', '{{ addslashes($document->original_filename ?? $document->title) }}', {{ $document->document_id }})"
                            class="text-primary-700 hover:underline font-medium">View original file</button>
                    </p>
                </div>
                <x-status-badge :status="$document->display_status" />
            </div>

            <x-lifecycle-stepper :document="$document" />

            @if($document->global_status === 'rejected' && !$document->nextVersion)
                <div class="mt-6 pt-6 border-t border-surface-200">
                    <details class="text-sm">
                        <summary class="cursor-pointer font-medium text-primary-700 hover:underline">Resubmit a revised version</summary>
                        <form method="POST" action="{{ route('originator.documents.resubmit', $document) }}" enctype="multipart/form-data" class="mt-3 space-y-3 max-w-sm">
                            @csrf
                            <div>
                                <label class="block text-xs font-medium text-surface-700 mb-1">Revised document</label>
                                <input type="file" name="file" required class="w-full text-xs">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-surface-700 mb-1">Due date &amp; time</label>
                                <p class="text-[11px] text-surface-400 mb-1">Must fall within working hours (9 AM–5 PM, Mon–Sat).</p>
                                <input type="datetime-local" id="resubmit-due-date" name="due_date" required min="{{ now()->addMinutes(config('sla.min_due_date_buffer_minutes', 15))->format('Y-m-d\TH:i') }}"
                                    class="w-full rounded-lg border-surface-300 text-sm px-3 py-2">
                                <p id="resubmit-due-date-warning" class="hidden mt-1 text-[11px] text-rejected-700">This falls outside working hours (9 AM–5 PM, Mon–Sat) or on a holiday — pick a different date/time.</p>
                            </div>
                            <button class="w-full bg-primary-700 hover:bg-primary-800 text-white text-xs font-medium py-2 rounded-lg">Resubmit</button>
                        </form>
                    </details>
                </div>
            @endif
        </div>

        {{-- flex-1 + min-h-0: lets this card's own inner scroll area (below)
             claim exactly the space left over after the header card above
             it, instead of growing past the viewport and forcing <main>
             (this app's real scroll container — see layouts/app.blade.php)
             to scroll the whole page. The precise height is set by JS
             (sizeDocumentTracker() in tracking.blade.php), recalculated on
             load/resize/live-refresh — a fixed CSS max-height can't do this
             correctly since the header card above varies in height. --}}
        <div id="document-tracker-card" class="bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden flex-1 min-h-0 flex flex-col">
            <div class="px-6 py-4 border-b border-surface-200 shrink-0">
                <h3 class="text-sm font-semibold text-surface-900">Document Tracker</h3>
            </div>
            @php $movementTimeline = \App\Services\DocumentMovementTimeline::build($document); @endphp
            {{-- Scrolls internally instead of the whole page — a long
                 tracker no longer forces scrolling past everything else on
                 the page just to read more of it. id targeted by
                 sizeDocumentTracker() in tracking.blade.php. --}}
            <div id="document-tracker-scroll" class="overflow-x-auto overflow-y-auto">
                <table class="w-full text-sm border-collapse">
                    {{-- No explicit z-index — sticky already paints above the
                         table's own scrolling rows from normal stacking order
                         alone; adding one here previously created a stacking
                         context that won against the notification dropdown
                         elsewhere on the page (z-30), making Document Tracker
                         labels incorrectly appear on top of it. --}}
                    <thead class="sticky top-0 bg-white">
                        <tr class="border-b-2 border-surface-200 text-left text-[11px] uppercase tracking-wide text-surface-400">
                            <th class="px-6 py-2 font-medium border-r border-surface-200">Timestamp</th>
                            <th class="px-4 py-2 font-medium border-r border-surface-200">Action</th>
                            <th class="px-4 py-2 font-medium border-r border-surface-200">Employee</th>
                            <th class="px-6 py-2 font-medium">Description</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-surface-200">
                        @forelse($movementTimeline as $event)
                            <tr>
                                <td class="px-6 py-3 text-xs text-surface-400 whitespace-nowrap align-top border-r border-surface-200">{{ $event['timestamp']->format('M j, Y g:i A') }}</td>
                                <td class="px-4 py-3 align-top border-r border-surface-200">
                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold whitespace-nowrap
                                        {{ $event['kind'] === 'session_group' ? 'bg-primary-50 text-primary-700' : 'bg-surface-100 text-surface-600' }}">
                                        {{ $event['label'] }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-surface-700 font-medium align-top whitespace-nowrap border-r border-surface-200">{{ $event['actor'] }}</td>
                                <td class="px-6 py-3 text-surface-500 align-top">
                                    @if($event['kind'] === 'session_group')
                                        {{-- One row per person, every individual pass
                                             shown plainly underneath — see
                                             DocumentMovementTimeline::build()'s docblock. --}}
                                        {{ $event['note'] }}
                                        <p class="mt-1 text-xs text-surface-400">{{ $event['passes_detail'] }}</p>
                                    @else
                                        {{ $event['note'] }}
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-6 py-6 text-center text-sm text-surface-400">No recorded activity yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-surface-200">
            <h3 class="text-sm font-semibold text-surface-900">Approval Stages</h3>
        </div>
        <div class="p-6">
            <x-workflow-stage-list :document="$document" />
        </div>
    </div>
</div>
