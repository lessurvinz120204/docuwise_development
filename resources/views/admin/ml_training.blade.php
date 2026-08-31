@extends('layouts.app')
@section('title', 'ML Training')
@section('page-title', 'Machine Learning Training')

@section('content')
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    @if(session('warning'))
        <div class="lg:col-span-3 rounded-xl bg-processing-50 border border-processing-500/25 text-processing-700 px-4 py-3 text-sm shadow-sm">
            <div class="flex items-center gap-2.5 font-medium mb-1">
                <svg class="w-5 h-5 flex-shrink-0 text-processing-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
                Possible near-duplicate samples
            </div>
            <ul class="list-disc list-inside space-y-0.5 pl-1">
                @foreach((array) session('warning') as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div id="ml-review-panels" class="contents"
        data-refresh-url="{{ route('admin.ml.review.refresh') }}"
        data-poll-url="{{ route('admin.ml.review.poll') }}">
        @include('admin.partials.ml_review_panels')
    </div>

    <div class="lg:col-span-2">
        <div class="bg-white rounded-xl shadow-card border border-surface-200 p-6">
            <h2 class="text-sm font-semibold text-surface-900 mb-3">Train / Retrain Classifier</h2>
            <ol class="space-y-2.5 mb-4">
                <li class="flex items-start gap-2.5 text-sm text-surface-700">
                    <span class="shrink-0 w-5 h-5 rounded-full bg-primary-50 text-primary-700 text-xs font-semibold flex items-center justify-center mt-0.5">1</span>
                    <span>For each category below, pick at least {{ $minPerCategory }} sample files, then click that category's own <strong class="font-semibold text-surface-900">"Add"</strong> button — do this once per category.</span>
                </li>
                <li class="flex items-start gap-2.5 text-sm text-surface-700">
                    <span class="shrink-0 w-5 h-5 rounded-full bg-primary-50 text-primary-700 text-xs font-semibold flex items-center justify-center mt-0.5">2</span>
                    <span>Once every category shows a green "staged" count, click <strong class="font-semibold text-surface-900">"Train Model"</strong> at the bottom. Selecting files alone does not stage them — you must click each category's "Add" button first.</span>
                </li>
            </ol>
            <p class="text-xs text-surface-400 mb-6">
                Staged samples are shared across every admin account, have no upper limit, and are kept even after training — no need to finish in one sitting, and no need to start over next time.
            </p>

            @php
                $incomplete = collect($categories)->filter(fn ($c) => ($stagedSamples->get($c, collect()))->count() < $minPerCategory)->values();
            @endphp

            <div class="space-y-6">
                @foreach($categories as $category)
                    @php
                        $samplesInCategory = $stagedSamples->get($category, collect());
                        $count = $samplesInCategory->count();
                    @endphp
                    <div class="border rounded-lg p-4 {{ $count >= $minPerCategory ? 'border-approved-300 bg-approved-50/30' : 'border-surface-200' }}">
                        <div class="flex items-center justify-between mb-2">
                            <label class="text-sm font-medium text-surface-800">
                                {{ $count >= $minPerCategory ? '✓' : '' }} {{ $category }}
                            </label>
                            <span class="text-xs {{ $count >= $minPerCategory ? 'text-approved-700 font-medium' : 'text-surface-400' }}">
                                {{ $count }} staged{{ $count < $minPerCategory ? " — need at least {$minPerCategory}" : '' }}
                            </span>
                        </div>

                        @if($samplesInCategory->isNotEmpty())
                            {{-- Collapsed by default — a category with dozens of staged
                                 samples (no lifetime cap anymore, see above) would otherwise
                                 force a long scroll past its own list just to reach the NEXT
                                 category's upload form below it. Plain <details>, not
                                 data-popover: this is "expand to inspect a list," not a
                                 transient menu, so it shouldn't auto-close on an outside
                                 click (see the click-outside handler's own comment in
                                 app.js for that exact distinction). --}}
                            <details class="mb-3 border border-surface-100 rounded-lg overflow-hidden">
                                <summary class="cursor-pointer select-none px-3 py-1.5 text-xs font-medium text-surface-600 bg-surface-50/50 hover:bg-surface-100">
                                    {{ $count }} staged sample{{ $count === 1 ? '' : 's' }} — click to view/remove
                                </summary>
                                <ul class="divide-y divide-surface-100 border-t border-surface-100">
                                    @foreach($samplesInCategory as $sample)
                                        <li class="flex items-center justify-between px-3 py-1.5 text-xs bg-surface-50/50">
                                            <span class="text-surface-600 truncate">
                                                {{ $sample->original_filename }}
                                                <span class="text-surface-400">— staged by {{ $sample->stagedBy->full_name ?? 'a former admin account' }}</span>
                                                @if($sample->trainedInModel)
                                                    <span class="text-approved-700 font-medium">&middot; ✓ included in {{ $sample->trainedInModel->version }}</span>
                                                @else
                                                    <span class="text-processing-700 font-medium">&middot; not yet included in the active model</span>
                                                @endif
                                            </span>
                                            <form method="POST" action="{{ route('admin.ml.training.sample.destroy', $sample) }}">
                                                @csrf @method('DELETE')
                                                <button type="submit" class="text-rejected-600 hover:underline flex-shrink-0 ml-2">Remove</button>
                                            </form>
                                        </li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif

                        <form method="POST" action="{{ route('admin.ml.training.stage', $category) }}" enctype="multipart/form-data" class="flex flex-wrap items-center gap-2">
                            @csrf
                            <input type="file" name="files[]" multiple required
                                accept=".pdf,.txt,.docx"
                                class="flex-1 min-w-[160px] text-xs text-surface-600 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-medium file:bg-primary-50 file:text-primary-700 hover:file:bg-primary-100">
                            <button type="submit"
                                class="flex-shrink-0 text-xs font-medium bg-primary-700 hover:bg-primary-800 text-white px-3 py-2 rounded-lg">
                                Add these files (up to {{ $batchUploadLimit }} per upload)
                            </button>
                        </form>

                        @if($count > 0)
                            <form method="POST" action="{{ route('admin.ml.training.stage.clear', $category) }}" class="mt-2">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-xs text-rejected-600 hover:underline">Clear all staged samples for this category</button>
                            </form>
                        @endif
                    </div>
                @endforeach

                @if($incomplete->isNotEmpty())
                    <div class="rounded-lg bg-surface-100 border border-surface-200 px-4 py-3 text-xs text-surface-600">
                        Still need samples staged for: <strong>{{ $incomplete->implode(', ') }}</strong>. Remember to click each category's own "Add these files" button — the button below won't work until all three are ready.
                    </div>
                @endif

                <form method="POST" action="{{ route('admin.ml.train') }}">
                    @csrf
                    <button type="submit" {{ $incomplete->isNotEmpty() ? 'disabled' : '' }}
                        class="w-full text-white text-sm font-medium py-2.5 rounded-lg transition-colors {{ $incomplete->isNotEmpty() ? 'bg-surface-300 cursor-not-allowed' : 'bg-primary-700 hover:bg-primary-800' }}">
                        Train Model
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="space-y-6">
        <div class="bg-white rounded-xl shadow-card border border-surface-200 p-6">
            <h2 class="text-sm font-semibold text-surface-900 mb-4">Current Active Model</h2>
            @if($activeModel)
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-surface-500">Version</dt><dd class="font-medium">{{ $activeModel->version }}</dd></div>
                    <div class="flex justify-between"><dt class="text-surface-500">Samples</dt><dd class="font-medium">{{ $activeModel->training_sample_count }}</dd></div>
                    <div class="flex justify-between"><dt class="text-surface-500">Accuracy</dt><dd class="font-medium text-approved-700">{{ $activeModel->accuracy_score }}%</dd></div>
                </dl>
            @else
                <p class="text-sm text-surface-400">No trained model yet — upload samples to get started.</p>
            @endif
        </div>

        <div class="bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-surface-200"><h3 class="text-xs font-semibold text-surface-900 uppercase tracking-wide">Training History</h3></div>
            <ul class="divide-y divide-surface-100 text-sm">
                @foreach($history as $m)
                    <li class="px-5 py-3 flex justify-between items-center">
                        <div>
                            <p class="font-medium text-surface-800">{{ $m->version }}</p>
                            <p class="text-xs text-surface-400">{{ $m->last_trained?->format('M j, Y g:i A') }}</p>
                        </div>
                        <span class="text-xs font-semibold {{ $m->is_active ? 'text-approved-700' : 'text-surface-400' }}">
                            {{ $m->is_active ? 'Active' : $m->accuracy_score . '%' }}
                        </span>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
</div>

<script>
    // Same live-update pattern as the admin dashboard's overview widget
    // (see admin/dashboard.blade.php) — a new low-confidence upload, or
    // another admin confirming/rejecting one, shows up here instantly via
    // Reverb instead of waiting for a manual reload.
    document.addEventListener('DOMContentLoaded', function () {
        const panelsEl = document.getElementById('ml-review-panels');
        if (!panelsEl) return;

        const opts = {
            refreshUrl: panelsEl.dataset.refreshUrl,
            target: panelsEl,
            // Was missing before — a poll/channel swap was silently
            // resetting whichever page of ML Review / Readability Review
            // you had open back to page 1, since it fetched refreshUrl
            // with no query string at all.
            preserveQueryString: true,
        };

        startLiveChannel('admin-dashboard', '.document.status-changed', opts);
        startLivePoll({ ...opts, pollUrl: panelsEl.dataset.pollUrl });

        // Both Awaiting ML Review and Content Readability Review
        // pagination live inside this one container — see
        // enableAjaxPagination()'s docblock in app.js for why one shared
        // fragment fetch correctly handles both.
        enableAjaxPagination(panelsEl, opts);
    });
</script>
@endsection
