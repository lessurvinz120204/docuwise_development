{{--
    One audit-trail row (Timestamp / Document Title / Actor / Action / Track
    / Description) — shared by admin/partials/audit-results.blade.php (the
    full Audit Trail table) AND the Control Center's Recent Activity panel,
    so a document/system row always looks and behaves identically wherever
    it's shown, not just similarly. Expects $row (kind: 'document' or
    'system', shaped by AdminController::buildAuditRows()/recentActivityRows())
    and the same $actionCategories/$categoryClasses/$actionLabels maps the
    including view already defined.
--}}
@if($row->kind === 'document')
    @php
        $doc = $row->document;
        $rowActionLabel = $doc->is_legacy_import ? 'Imported' : 'Uploaded';
        $rowBadgeClass = $categoryClasses['lifecycle'];
    @endphp
    <tr class="audit-row audit-row-document hover:bg-surface-50 cursor-pointer"
        data-document-title="{{ strtolower($doc->title) }}" data-document-id="{{ $doc->document_id }}"
        onclick="document.getElementById('movements-{{ $doc->document_id }}').classList.toggle('hidden'); this.querySelector('.audit-expand-icon').classList.toggle('rotate-90')">
        <td class="px-6 py-3 text-surface-500 whitespace-nowrap align-top border-r border-surface-200">{{ $doc->upload_date?->format('M j, Y g:i:s A') }}</td>
        <td class="px-6 py-3 text-surface-800 font-medium align-top border-r border-surface-200">{{ $doc->title }}</td>
        <td class="px-6 py-3 text-surface-700 align-top border-r border-surface-200">{{ $doc->originator->full_name ?? 'System' }}</td>
        <td class="px-6 py-3 align-top border-r border-surface-200">
            <span class="text-xs font-semibold px-2 py-0.5 rounded-full {{ $rowBadgeClass }}">{{ $rowActionLabel }}</span>
        </td>
        <td class="px-6 py-3 text-surface-500 align-top whitespace-nowrap border-r border-surface-200">
            <a href="{{ route('documents.track', $doc) }}" onclick="event.stopPropagation()" class="text-primary-700 hover:underline font-medium">View &rarr;</a>
        </td>
        <td class="px-6 py-3 text-surface-600 align-top">
            <svg class="audit-expand-icon inline-block w-3 h-3 mr-1 text-surface-400 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
            Click to view full movement history for this document.
        </td>
    </tr>
    <tr id="movements-{{ $doc->document_id }}" class="hidden bg-surface-50/60">
        <td colspan="6" class="px-6 py-3">
            <div class="border border-surface-200 rounded-lg overflow-hidden bg-white">
                <x-document-movement-timeline :document="$doc" />
            </div>
        </td>
    </tr>
@else
    @php
        $log = $row->log;
        $rowCategory = $actionCategories[$log->action_type] ?? 'config';
        $rowBadgeClass = $categoryClasses[$rowCategory];
    @endphp
    <tr class="audit-row" data-document-title="" data-document-id="">
        <td class="px-6 py-3 text-surface-500 whitespace-nowrap align-top border-r border-surface-200">{{ $log->timestamp->format('M j, Y g:i:s A') }}</td>
        <td class="px-6 py-3 text-surface-400 align-top border-r border-surface-200">—</td>
        <td class="px-6 py-3 text-surface-700 align-top border-r border-surface-200">{{ $log->user->full_name ?? 'System' }}</td>
        <td class="px-6 py-3 align-top border-r border-surface-200">
            <span class="text-xs font-semibold px-2 py-0.5 rounded-full {{ $rowBadgeClass }}">{{ $actionLabels[$log->action_type] ?? ucfirst(str_replace('_', ' ', $log->action_type)) }}</span>
        </td>
        <td class="px-6 py-3 text-surface-400 align-top whitespace-nowrap border-r border-surface-200">—</td>
        <td class="px-6 py-3 text-surface-600 align-top">{{ $log->description }}</td>
    </tr>
@endif
