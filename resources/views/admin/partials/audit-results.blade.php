{{--
    Audit Logs results table — split out from audit_logs.blade.php so the
    same markup can be rendered two ways: a normal full page load, and a
    fragment returned by AdminController::auditLogsRefresh() for the
    live-poll JS to swap in place (see audit_logs.blade.php) without a
    full page reload.
--}}
@php
    // Same five-bucket categorization App\Services\DocumentMovementTimeline
    // uses for the nested per-document timeline — shared, not duplicated,
    // so an action is always the same color everywhere it's shown.
    $actionCategories = \App\Services\DocumentMovementTimeline::ACTION_CATEGORIES;
    $categoryClasses = \App\Services\DocumentMovementTimeline::CATEGORY_CLASSES;
    $actionLabels = \App\Services\DocumentMovementTimeline::ACTION_LABELS;
@endphp

{{-- id targeted by sizeAuditTable() in audit_logs.blade.php — the PAGE
     never scrolls, this scrolls internally instead, same principle as
     Document Tracker/the Control Center's Recent Activity. --}}
<div id="audit-table-scroll" class="overflow-x-auto overflow-y-auto">
<table class="w-full min-w-[720px] text-sm">
    <thead class="sticky top-0 bg-surface-50 text-surface-500 text-xs uppercase tracking-wide">
        <tr>
            <th class="text-left px-6 py-3 font-medium border-r border-surface-200">Timestamp</th>
            <th class="text-left px-6 py-3 font-medium border-r border-surface-200">Document Title</th>
            <th class="text-left px-6 py-3 font-medium border-r border-surface-200">Employee</th>
            <th class="text-left px-6 py-3 font-medium border-r border-surface-200">Action</th>
            <th class="text-left px-6 py-3 font-medium border-r border-surface-200">Track</th>
            <th class="text-left px-6 py-3 font-medium">Description</th>
        </tr>
    </thead>
    <tbody id="audit-rows" class="divide-y divide-surface-200">
        @forelse($logs as $row)
            @include('admin.partials.audit-row', ['row' => $row])
        @empty
            <tr id="audit-empty">
                <td colspan="6" class="px-6 py-10 text-center text-surface-400 text-sm">No audit entries match these filters.</td>
            </tr>
        @endforelse
        <tr id="audit-no-matches" class="hidden">
            <td colspan="6" class="px-6 py-10 text-center text-surface-400 text-sm">No entries on this page match "<span id="audit-no-matches-term"></span>". Press Enter to search every page.</td>
        </tr>
    </tbody>
</table>
</div>
<div id="audit-pagination-footer" class="px-6 py-4 border-t border-surface-200">{{ $logs->links() }}</div>
