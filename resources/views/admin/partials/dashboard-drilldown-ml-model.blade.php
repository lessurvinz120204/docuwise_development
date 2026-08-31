{{--
    Opened via the "Active ML Model" KPI card (see overview.blade.php) —
    same openKpiDrilldown() modal every other KPI card already uses, just
    showing model details instead of a document/user list. Content is the
    same fields the old inline dashboard panel showed before it was
    replaced by this card (see that commit's history), just with more
    breathing room since the modal is far larger than a KPI tile.
--}}
<div class="p-6 max-w-2xl mx-auto">
    @if($activeModel)
        <dl class="grid grid-cols-2 gap-x-6 gap-y-4 text-sm">
            <div class="col-span-2"><dt class="text-surface-500 text-xs uppercase tracking-wide mb-1">Algorithm</dt><dd class="font-medium text-surface-900 text-base">{{ $activeModel->model_name }}</dd></div>
            <div><dt class="text-surface-500 text-xs uppercase tracking-wide mb-1">Version</dt><dd class="font-medium tabular-nums">{{ $activeModel->version }}</dd></div>
            <div><dt class="text-surface-500 text-xs uppercase tracking-wide mb-1">Training samples</dt><dd class="font-medium tabular-nums">{{ $activeModel->training_sample_count }}</dd></div>
            <div><dt class="text-surface-500 text-xs uppercase tracking-wide mb-1">Est. accuracy</dt><dd class="font-medium text-approved-700 tabular-nums">{{ $activeModel->accuracy_score }}%</dd></div>
            <div><dt class="text-surface-500 text-xs uppercase tracking-wide mb-1">Last trained</dt><dd class="font-medium">{{ $activeModel->last_trained->diffForHumans() }}</dd></div>
        </dl>

        @if($modelHistory->count() > 1)
            <div class="mt-6 pt-6 border-t border-surface-200">
                <h3 class="text-xs font-semibold text-surface-500 uppercase tracking-wide mb-3">Version History</h3>
                <ul class="space-y-2.5">
                    @foreach($modelHistory as $m)
                        <li class="flex items-center justify-between text-sm gap-2">
                            <span class="flex items-center gap-2 text-surface-600 truncate">
                                <span class="w-2 h-2 rounded-full shrink-0 {{ $m->is_active ? 'bg-approved-500' : 'bg-surface-300' }}" title="{{ $m->is_active ? 'Active' : 'Retired' }}"></span>
                                <span class="font-medium tabular-nums">{{ $m->version }}</span>
                                <span class="text-surface-400 shrink-0">&middot; {{ $m->last_trained?->format('M j, Y') ?? '—' }}</span>
                            </span>
                            <span class="font-medium tabular-nums shrink-0 {{ $m->is_active ? 'text-approved-700' : 'text-surface-500' }}">{{ $m->accuracy_score }}%</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    @else
        <p class="text-sm text-surface-400 text-center py-8">No model trained yet.</p>
    @endif

    <a href="{{ route('admin.ml.training') }}" class="mt-6 inline-block text-sm font-medium text-primary-700 hover:underline">Manage training data &rarr;</a>
</div>
