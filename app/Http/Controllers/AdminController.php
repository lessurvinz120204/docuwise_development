<?php

namespace App\Http\Controllers;

use App\Events\AccountDeactivated;
use App\Events\DocumentStatusChanged;
use App\Mail\AutoApprovalDisputedMail;
use App\Models\AuditLog;
use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\DocumentReviewSession;
use App\Models\MlModelRepository;
use App\Models\MlStagingSample;
use App\Models\NotificationRecord;
use App\Models\SlaHoliday;
use App\Models\SlaViolation;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\ClassificationService;
use App\Services\SlaService;
use App\Services\TextExtractionService;
use App\Services\ValidationService;
use App\Services\WorkflowService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    public function __construct(
        private ClassificationService $classifier,
        private TextExtractionService $extractor,
        private SlaService $sla,
        private WorkflowService $workflow,
        private ValidationService $validator,
    ) {
    }

    /**
     * The KPI stats + SLA alert list — shared by dashboard() (full page),
     * refresh() (the AJAX fragment the live-poll JS swaps in), and poll()
     * (which reuses the same cheap COUNT queries as its "did anything
     * change" signal, since they're already inexpensive).
     */
    private function overviewData(): array
    {
        $stats = [
            'total_documents' => DocumentRepository::count(),
            'pending' => DocumentRepository::whereIn('global_status', ['processing', 'classified_validated'])->count(),
            'approved' => DocumentRepository::whereIn('global_status', ['approved', 'auto_approved'])->count(),
            'rejected' => DocumentRepository::where('global_status', 'rejected')->count(),
            'active_users' => User::where('is_active', true)->count(),
            'ml_review_count' => DocumentRepository::where('ml_review_status', 'pending')->count(),
            'readability_review_count' => DocumentRepository::where('readability_review_status', 'pending')->count(),
            'violations_count' => SlaViolation::count(),
        ];

        $slaAlerts = DocumentAssignment::where('escalated_to_admin', true)
            ->whereNull('admin_override_at')
            ->where('individual_status', 'pending')
            ->with(['document', 'stage'])
            ->orderBy('sla_expires_at')
            ->get();

        $reviewCount = DocumentAssignment::where('auto_approved', true)->whereNull('admin_reviewed_at')->count();

        return [$stats, $slaAlerts, $reviewCount];
    }

    /**
     * Heavier "module overview" data — analytics summary and a
     * recent-activity feed — split out from overviewData() so
     * overviewPoll() (fired every ~45-75s purely to detect change) stays
     * cheap; only the full-page dashboard() and its live-swap counterpart
     * overviewRefresh() need this.
     *
     * Deliberately does NOT include the analytics chart/KPI/table data —
     * that's a separately interactive sub-widget (one reusable panel, its
     * content swapped via analyticsPanelRefresh() when the admin changes
     * the Day/Week/Month/Year tab or the date filter — see
     * admin/partials/analytics-panel.blade.php). Bundling it in here would
     * mean this page's periodic live-refresh silently resets whatever
     * granularity/date the admin currently has selected back to the
     * default every ~45-75s.
     */
    private function dashboardExtras(): array
    {
        $recentActivity = $this->recentActivityRows();

        $analytics = $this->analyticsSummary();

        return [$recentActivity, $analytics];
    }

    /**
     * Recent Activity (Feature: match the Audit Trail's own row shape/
     * styling exactly — same $row->kind ('document'/'system') shape
     * buildAuditRows() produces, rendered through the same shared
     * admin.partials.audit-row partial) — bounded to the last $limit
     * DOCUMENT uploads and $limit SYSTEM log entries before merging/
     * sorting/trimming, unlike buildAuditRows() itself, which deliberately
     * pulls every document/log unfiltered since it expects to paginate a
     * full page. That's too heavy to run on every dashboard load/poll —
     * this stays cheap by bounding each side of the union before the merge.
     */
    private function recentActivityRows(int $limit = 5): \Illuminate\Support\Collection
    {
        $documentRows = DocumentRepository::with('originator')
            ->orderByDesc('upload_date')
            ->limit($limit)
            ->get()
            ->map(fn (DocumentRepository $doc) => (object) [
                'kind' => 'document',
                'sort_at' => $doc->upload_date,
                'document' => $doc,
            ]);

        $systemRows = AuditLog::with('user')
            ->whereNull('document_id')
            ->orderByDesc('timestamp')
            ->limit($limit)
            ->get()
            ->map(fn (AuditLog $log) => (object) [
                'kind' => 'system',
                'sort_at' => $log->timestamp,
                'log' => $log,
            ]);

        return $documentRows->concat($systemRows)->sortByDesc('sort_at')->take($limit)->values();
    }

    /**
     * The parts of the Analytics card that are NOT the interactive
     * chart panel: peak upload day/hour, category volume, and the current
     * backlog — all all-time/live snapshots, deliberately not scoped to
     * whatever Day/Week/Month/Year tab or date filter the admin currently
     * has the chart panel set to (see analyticsPanelData() below), since
     * "which categories are busiest overall" and "how much is in flight
     * right now" are more useful as a constant reference point than
     * something that resets depending on the chart's current filter.
     */
    private function analyticsSummary(): array
    {
        $uploadDates = DocumentRepository::pluck('upload_date');
        $peakDay = $uploadDates->countBy(fn ($d) => $d->format('l'))->sortDesc()->keys()->first();
        $peakHour = $uploadDates->countBy(fn ($d) => (int) $d->format('G'))->sortDesc()->keys()->first();

        $categoryVolume = DocumentRepository::whereNotNull('ml_category')
            ->selectRaw('ml_category, count(*) as cnt')
            ->groupBy('ml_category')
            ->orderByDesc('cnt')
            ->pluck('cnt', 'ml_category');

        $backlogCount = DocumentRepository::whereIn('global_status', ['processing', 'classified_validated'])->count();

        return [
            'peak_day' => $peakDay,
            'peak_hour' => $peakHour !== null ? sprintf('%02d:00–%02d:00', $peakHour, ($peakHour + 1) % 24) : null,
            'category_volume' => $categoryVolume,
            'backlog_count' => $backlogCount,
        ];
    }

    /**
     * Config per granularity: the Carbon unit to step by, the bucket-key
     * format, how many periods the rolling window covers, the label used
     * for the detail table's period column, and the label used for the
     * KPI tiles' "vs previous ___" trend tooltip.
     *
     * 'day' steps by HOUR across a single calendar day (00:00-23:59 of
     * whichever date is selected), not a multi-day rolling window like
     * the other three — a 14-day window meant most of the chart sat empty
     * with all the real activity crammed against the right edge (today).
     * A single day's hourly timeline doesn't have that skew: whatever
     * hours had activity are spread across the full width on their own
     * terms. label is "Hour" (each row IS an hour) but trend_label stays
     * "day" (see analyticsPanelData() — the KPI tiles trend today's
     * totals against yesterday's, not one hour against the last).
     */
    private const ANALYTICS_GRANULARITIES = [
        'day' => ['unit' => 'hour', 'format' => 'H:00', 'count' => 24, 'label' => 'Hour', 'trend_label' => 'day'],
        'week' => ['unit' => 'week', 'format' => 'o-\WW', 'count' => 12, 'label' => 'Week', 'trend_label' => 'week'],
        'month' => ['unit' => 'month', 'format' => 'Y-m', 'count' => 12, 'label' => 'Month', 'trend_label' => 'month'],
        'year' => ['unit' => 'year', 'format' => 'Y', 'count' => 5, 'label' => 'Year', 'trend_label' => 'year'],
    ];

    /**
     * The ONE reusable Analytics chart panel's data (KPI tiles + chart
     * rows) for a single granularity + reference date — this is the only
     * method that computes chart data; switching the Day/Week/Month/Year
     * tab or applying the date filter both just call this again with
     * different arguments (see analyticsPanelRefresh() and dashboard()
     * below), never a second, parallel computation.
     *
     * $asOf defaults to "now" — the live, un-filtered view — and the
     * window always ENDS at $asOf, not always at "today", so picking a
     * past date re-anchors the whole rolling window to look back from
     * that point instead.
     */
    private function analyticsPanelData(string $granularity, ?\Carbon\Carbon $asOf = null): array
    {
        $granularity = array_key_exists($granularity, self::ANALYTICS_GRANULARITIES) ? $granularity : 'day';
        $cfg = self::ANALYTICS_GRANULARITIES[$granularity];
        $asOf = ($asOf ?? now())->copy();

        $until = match ($cfg['unit']) {
            'hour' => $asOf->copy()->endOfDay(),
            'week' => $asOf->copy()->endOfWeek(),
            'month' => $asOf->copy()->endOfMonth(),
            'year' => $asOf->copy()->endOfYear(),
        };
        $since = match ($cfg['unit']) {
            'hour' => $asOf->copy()->startOfDay(),
            'week' => $asOf->copy()->subWeeks($cfg['count'] - 1)->startOfWeek(),
            'month' => $asOf->copy()->subMonths($cfg['count'] - 1)->startOfMonth(),
            'year' => $asOf->copy()->subYears($cfg['count'] - 1)->startOfYear(),
        };

        $chartRows = $this->analyticsBuckets($cfg['unit'], $cfg['format'], $since, $until);

        // The hourly Day tab needs its KPI tiles to summarize the WHOLE
        // day, trended against the whole of yesterday — not the most
        // recent hour trended against the hour before it, which would be
        // noise (a document system isn't active every single hour) rather
        // than a meaningful signal. Every other granularity's last bucket
        // already IS one full period, so it can be used directly.
        if ($cfg['unit'] === 'hour') {
            $current = $this->analyticsAggregateRow($chartRows);
            $previousDayRows = $this->analyticsBuckets('hour', $cfg['format'], $since->copy()->subDay(), $until->copy()->subDay());
            $previous = $this->analyticsAggregateRow($previousDayRows);

            // The raw "H:00" grouping key doesn't carry the actual calendar
            // date and reads in 24-hour time — confusing on its own once
            // you're looking at a specific chosen date rather than "today"
            // by default. The chart hover, readout, and detail table all
            // display $row->bucket directly, so rewriting it once here
            // (into e.g. "Aug 8, 2026, 11:00 PM") fixes all three at once.
            foreach ($chartRows as $row) {
                $hour = (int) explode(':', $row->bucket)[0];
                $row->bucket = $asOf->copy()->startOfDay()->addHours($hour)->format('M j, Y, g:i A');
            }
        } else {
            $current = $chartRows[count($chartRows) - 1] ?? null;
            $previous = $chartRows[count($chartRows) - 2] ?? null;
        }

        return [
            'granularity' => $granularity,
            'label' => $cfg['label'],
            'trend_label' => $cfg['trend_label'],
            'as_of' => $asOf->toDateString(),
            'chart_rows' => $chartRows,
            'kpi' => $this->analyticsKpis($current, $previous),
        ];
    }

    /**
     * Sums a list of bucket rows (see analyticsBuckets()) into one
     * combined row of the same shape — used to roll the Day tab's 24
     * hourly buckets up into "today" (and, for the trend comparison,
     * "yesterday"). avg_minutes is weighted by each bucket's own decided
     * count rather than a flat average of per-hour averages, so an hour
     * with one decision doesn't count as much as an hour with ten.
     */
    private function analyticsAggregateRow(array $rows): object
    {
        $decidedRows = array_filter($rows, fn ($r) => $r->avg_minutes !== null);
        $totalDecided = array_sum(array_map(fn ($r) => $r->approved + $r->rejected, $decidedRows));

        return (object) [
            'bucket' => null,
            'uploaded' => array_sum(array_map(fn ($r) => $r->uploaded, $rows)),
            'approved' => array_sum(array_map(fn ($r) => $r->approved, $rows)),
            'rejected' => array_sum(array_map(fn ($r) => $r->rejected, $rows)),
            'auto_approved' => array_sum(array_map(fn ($r) => $r->auto_approved, $rows)),
            'avg_minutes' => $totalDecided > 0
                ? (int) round(array_sum(array_map(fn ($r) => $r->avg_minutes * ($r->approved + $r->rejected), $decidedRows)) / $totalDecided)
                : null,
            'violations' => array_sum(array_map(fn ($r) => $r->violations, $rows)),
            // Safe to sum across buckets — a document is decided exactly
            // once, so it's counted in exactly one bucket's
            // violated_documents, never double-counted across the sum.
            'violated_documents' => array_sum(array_map(fn ($r) => $r->violated_documents, $rows)),
        ];
    }

    /**
     * KPI tiles for one "current" bucket (or aggregate — see
     * analyticsAggregateRow()) plus a % trend against the "previous" one.
     * Rates (approval/auto-approval/SLA-violation) are computed against
     * decisions actually made in that period, not uploads, since a
     * document uploaded in one period can easily be decided in a later
     * one — approved/rejected counts are the meaningful denominator for
     * "how did decisions go this period," uploaded is a separate,
     * unrelated volume metric shown alongside it.
     */
    private function analyticsKpis($currentRow, $previousRow): array
    {
        $rate = fn (?int $num, int $den) => $den > 0 ? round($num / $den * 100, 1) : null;

        $summarize = function ($row) use ($rate) {
            if (!$row) {
                return null;
            }
            $decidedTotal = $row->approved + $row->rejected;

            return [
                'uploaded' => $row->uploaded,
                'approval_rate' => $rate($row->approved, $decidedTotal),
                'auto_approval_rate' => $rate($row->auto_approved, $decidedTotal),
                'avg_minutes' => $row->avg_minutes,
                // violated_documents (not the raw 'violations' event
                // count) — it's a subset of decidedTotal by construction
                // (see analyticsBuckets()), so this can never exceed 100%,
                // unlike dividing by a raw event count that can outnumber
                // the documents it happened on.
                'sla_violation_rate' => $rate($row->violated_documents, $decidedTotal),
            ];
        };

        $current = $summarize($currentRow);
        $previous = $summarize($previousRow);

        $trendOf = function (string $metric) use ($current, $previous) {
            if (!$current || !$previous || $current[$metric] === null || $previous[$metric] === null || $previous[$metric] == 0) {
                return null;
            }

            return round((($current[$metric] - $previous[$metric]) / $previous[$metric]) * 100, 1);
        };

        return [
            'current' => $current,
            'trend' => $current ? [
                'uploaded' => $trendOf('uploaded'),
                'approval_rate' => $trendOf('approval_rate'),
                'auto_approval_rate' => $trendOf('auto_approval_rate'),
                'avg_minutes' => $trendOf('avg_minutes'),
                'sla_violation_rate' => $trendOf('sla_violation_rate'),
            ] : null,
        ];
    }

    /**
     * One row per period bucket between $since and $until INCLUSIVE, one
     * row per period even when nothing happened that period (zero-filled)
     * — a continuous timeline is what makes the line chart actually read
     * as a trend; skipping empty periods would make it jump between
     * non-adjacent points as if they were consecutive. $unit is a Carbon
     * add*()-compatible unit name ('hour'/'week'/'month'/'year') used to
     * step from $since to $until.
     */
    private function analyticsBuckets(string $unit, string $carbonFormat, \Carbon\Carbon $since, \Carbon\Carbon $until): array
    {
        $uploadBuckets = DocumentRepository::whereBetween('upload_date', [$since, $until])
            ->pluck('upload_date')
            ->groupBy(fn ($d) => $d->format($carbonFormat));

        $decidedBuckets = DocumentRepository::whereIn('global_status', ['approved', 'rejected', 'auto_approved'])
            ->whereBetween('updated_at', [$since, $until])
            ->get(['document_id', 'upload_date', 'updated_at', 'global_status'])
            ->groupBy(fn ($d) => $d->updated_at->format($carbonFormat));

        $violationBuckets = SlaViolation::whereBetween('violation_timestamp', [$since, $until])
            ->pluck('violation_timestamp')
            ->groupBy(fn ($v) => $v->format($carbonFormat));

        // Which of the documents decided in this whole window have EVER had
        // an SLA violation logged against them — deliberately not scoped to
        // violation_timestamp falling in the same window, since a
        // violation can predate its document's eventual decision by any
        // amount. Fetched once for the whole range (not per bucket) to
        // avoid an N+1 query per period; used below for
        // 'violated_documents', a document-count metric distinct from
        // 'violations' (a raw event count — one document can rack up more
        // than one violation now that a stage can have several approvers
        // in parallel, each independently escalating).
        $decidedDocIds = $decidedBuckets->flatten()->pluck('document_id');
        $violatedDocIds = SlaViolation::whereIn('document_id', $decidedDocIds)
            ->pluck('document_id')->unique()->flip();

        $bucketKeys = [];
        $cursor = $since->copy();
        while ($cursor->lte($until)) {
            $bucketKeys[] = $cursor->format($carbonFormat);
            $cursor = match ($unit) {
                'hour' => $cursor->addHour(),
                'week' => $cursor->addWeek(),
                'month' => $cursor->addMonth(),
                'year' => $cursor->addYear(),
            };
        }

        return collect($bucketKeys)->map(function ($bucket) use ($uploadBuckets, $decidedBuckets, $violationBuckets, $violatedDocIds) {
            $decided = $decidedBuckets->get($bucket, collect());

            return (object) [
                'bucket' => $bucket,
                'uploaded' => $uploadBuckets->get($bucket, collect())->count(),
                'approved' => $decided->whereIn('global_status', ['approved', 'auto_approved'])->count(),
                'rejected' => $decided->where('global_status', 'rejected')->count(),
                'auto_approved' => $decided->where('global_status', 'auto_approved')->count(),
                'avg_minutes' => $decided->isNotEmpty()
                    ? (int) round($decided->avg(fn ($d) => $d->upload_date->diffInMinutes($d->updated_at)))
                    : null,
                // Raw violation-event count in this period — a distinct,
                // still-correct metric on its own (shown in the detail
                // table), NOT the basis for the SLA Violation Rate KPI
                // (see 'violated_documents' below and analyticsKpis()).
                'violations' => $violationBuckets->get($bucket, collect())->count(),
                // How many of THIS bucket's decided documents have ever had
                // a violation logged — a subset of $decided, so dividing
                // this by the decided count can never exceed 100%, unlike
                // the raw event count above.
                'violated_documents' => $decided->pluck('document_id')->unique()
                    ->filter(fn ($id) => $violatedDocIds->has($id))->count(),
            ];
        })->all();
    }

    /** Admin control-center overview. */
    /**
     * Reads the analytics panel's requested granularity/as-of date off the
     * request — shared by dashboard() (so a bookmarked/shared URL with
     * ?granularity=&as_of= renders that exact view on first load, not
     * always the default) and analyticsPanelRefresh() (the AJAX swap).
     * Invalid/missing granularity falls back to 'day'; invalid/missing
     * as_of falls back to null (analyticsPanelData() then defaults to now()).
     */
    private function analyticsPanelRequestArgs(Request $request): array
    {
        $granularity = $request->string('granularity')->toString();
        $granularity = array_key_exists($granularity, self::ANALYTICS_GRANULARITIES) ? $granularity : 'day';

        $asOf = null;
        if ($request->filled('as_of')) {
            try {
                $asOf = \Carbon\Carbon::parse($request->string('as_of')->toString());
            } catch (\Exception) {
                $asOf = null;
            }
        }

        return [$granularity, $asOf];
    }

    public function dashboard(Request $request)
    {
        [$stats, $slaAlerts, $reviewCount] = $this->overviewData();
        [$recentActivity, $analytics] = $this->dashboardExtras();
        $activeModel = MlModelRepository::active();
        $modelHistory = $this->modelHistory();

        [$granularity, $asOf] = $this->analyticsPanelRequestArgs($request);
        $panel = $this->analyticsPanelData($granularity, $asOf);

        return view('admin.dashboard', compact(
            'stats', 'slaAlerts', 'reviewCount', 'activeModel', 'modelHistory',
            'recentActivity', 'analytics', 'panel'
        ));
    }

    /**
     * The Active ML Model card's version history — the last few trained
     * versions (including the currently active one), newest first, so an
     * admin can see at a glance whether accuracy has been trending up or
     * down across retrains instead of only ever seeing the single active
     * snapshot. Same query shape already used by the ML Training page
     * (see mlTrainingData()'s $history) — reused here rather than
     * duplicated, just capped tighter since this is a sidebar card, not a
     * dedicated page.
     */
    private function modelHistory(int $limit = 4): \Illuminate\Support\Collection
    {
        return MlModelRepository::orderByDesc('last_trained')->limit($limit)->get();
    }

    /**
     * The ONE reusable Analytics chart panel's fragment — fetched via AJAX
     * whenever the admin changes the Day/Week/Month/Year tab or applies
     * the date filter, swapping in place instead of a page reload (see
     * the script in admin/partials/overview.blade.php). Same data method
     * as the initial page load (analyticsPanelData()), just returning the
     * panel fragment instead of the whole dashboard.
     */
    public function analyticsPanelRefresh(Request $request)
    {
        [$granularity, $asOf] = $this->analyticsPanelRequestArgs($request);
        $panel = $this->analyticsPanelData($granularity, $asOf);

        return view('admin.partials.analytics-panel', compact('panel'));
    }

    /**
     * Fragment listing the documents/users behind a clicked KPI card
     * (Feature: clickable dashboard cards) — reuses the exact same
     * global_status groupings as overviewData()'s stats, so the list
     * shown always matches what the card's own number counted.
     */
    public function dashboardDrilldown(string $type)
    {
        $labels = [
            'total' => 'All Documents',
            'pending' => 'In Progress',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            'users' => 'Active Users',
        ];
        abort_unless(array_key_exists($type, $labels), 404);

        if ($type === 'users') {
            $users = User::where('is_active', true)->orderBy('full_name')->limit(100)->get();

            return view('admin.partials.dashboard-drilldown-users', ['users' => $users, 'label' => $labels[$type]]);
        }

        $showDecision = in_array($type, ['approved', 'rejected'], true);

        $query = DocumentRepository::with('originator')->orderByDesc('upload_date');
        if ($showDecision) {
            $query->with(['assignments.approver', 'assignments.adminOverrideBy']);
        }
        match ($type) {
            'pending' => $query->whereIn('global_status', ['processing', 'classified_validated']),
            'approved' => $query->whereIn('global_status', ['approved', 'auto_approved']),
            'rejected' => $query->where('global_status', 'rejected'),
            default => null, // 'total' — no filter
        };

        $total = $query->count();
        $documents = $query->limit(50)->get();

        $decisions = $showDecision
            ? $documents->mapWithKeys(fn (DocumentRepository $doc) => [$doc->document_id => $this->resolveDecision($doc)])
            : null;

        return view('admin.partials.dashboard-drilldown-documents', [
            'documents' => $documents, 'total' => $total, 'label' => $labels[$type], 'decisions' => $decisions,
        ]);
    }

    /**
     * Fragment listing every document uploaded on one calendar date
     * (Feature: click a date on the Admin Calendar, see what came in that
     * day) — reuses the same drill-down modal and document-list fragment
     * as dashboardDrilldown() above, just filtered by upload_date instead
     * of global_status. No decision context here — the calendar is about
     * volume/timing, not outcomes.
     */
    public function documentsOnDate(string $date)
    {
        $documents = DocumentRepository::with('originator')
            ->whereDate('upload_date', $date)
            ->orderByDesc('upload_date')
            ->get();

        return view('admin.partials.dashboard-drilldown-documents', [
            'documents' => $documents,
            'total' => $documents->count(),
            'label' => \Carbon\Carbon::parse($date)->format('M j, Y'),
            'decisions' => null,
        ]);
    }

    /**
     * Who actually decided a document's fate, and when — used by the
     * Approved/Rejected dashboard drill-downs. Not simply "the last stage
     * on record": a rejection auto-closes every other pending stage (see
     * WorkflowService::completeStage()), and stages can complete out of
     * sequence order, so the deciding assignment is whichever one
     * genuinely drove the outcome — identified via cascade_closed_by
     * being null, not by sniffing the comment text (which now carries the
     * real rejection reason, copied over to every cascade-closed seat too
     * — see completeStage()'s docblock).
     */
    private function resolveDecision(DocumentRepository $doc): array
    {
        if ($doc->is_legacy_import) {
            return ['by' => 'Admin (Legacy Import)', 'at' => $doc->upload_date];
        }

        $wantStatus = $doc->global_status === 'rejected' ? 'rejected' : 'approved';

        $decisive = $doc->assignments
            ->where('individual_status', $wantStatus)
            ->when($wantStatus === 'rejected', fn ($c) => $c->filter(
                fn (DocumentAssignment $a) => is_null($a->cascade_closed_by)
            ))
            ->sortByDesc('acted_at')
            ->first();

        if (!$decisive) {
            return ['by' => '—', 'at' => null];
        }

        if ($decisive->admin_override_by) {
            return ['by' => 'Admin Override', 'at' => $decisive->admin_override_at];
        }

        if ($decisive->auto_approved) {
            return ['by' => 'System Auto-Approval', 'at' => $decisive->acted_at];
        }

        return ['by' => $decisive->approver->full_name ?? '—', 'at' => $decisive->acted_at];
    }

    /**
     * Renders the KPI cards + SLA alerts + Active ML Model fragment
     * (admin/partials/overview.blade.php) for the dashboard's live-poll JS
     * to swap in place — see resources/js/app.js's startLivePoll() and
     * dashboard.blade.php for why this beats a full page reload. The ML
     * Model panel is included here too, even though it rarely changes,
     * purely so the whole 3-column grid row (SLA alerts + ML model side
     * by side) stays one swap target instead of splitting the layout
     * across two independently-swapped pieces.
     */
    public function overviewRefresh()
    {
        [$stats, $slaAlerts, $reviewCount] = $this->overviewData();
        [$recentActivity, $analytics] = $this->dashboardExtras();
        $activeModel = MlModelRepository::active();
        $modelHistory = $this->modelHistory();

        return view('admin.partials.overview', compact(
            'stats', 'slaAlerts', 'reviewCount', 'activeModel', 'modelHistory',
            'recentActivity', 'analytics'
        ));
    }

    /**
     * Lightweight JSON endpoint the dashboard's JS polls every ~5-10s.
     * Reuses the same COUNT queries overviewData() already runs — they're
     * cheap enough that there's no separate "cheaper" signal worth
     * computing just for the poll.
     */
    public function overviewPoll()
    {
        [$stats, $slaAlerts, $reviewCount] = $this->overviewData();

        return response()->json([
            'stats' => $stats,
            'sla_alert_count' => $slaAlerts->count(),
            'review_count' => $reviewCount,
            // Fallback-path signals for what AdminActivityLogged covers over
            // the WebSocket — the poll can't "listen" for that event, so it
            // detects the same changes structurally instead: a new audit
            // log row covers logins/uploads/decisions/escalations/etc.,
            // and a busy-flag signature covers is_busy toggles specifically
            // (that path writes no audit log, so latest_log_id alone
            // wouldn't catch it — see User::booted()).
            'latest_log_id' => AuditLog::max('log_id'),
            'busy_signature' => User::where('role', 'approver')->orderBy('user_id')
                ->pluck('is_busy')->map(fn ($busy) => $busy ? '1' : '0')->implode(','),
        ]);
    }

    // ---------------------------------------------------------------
    // User account management (Section 3: Account ID <-> workflow role)
    // ---------------------------------------------------------------

    public function users(Request $request)
    {
        $stagesByCategory = WorkflowStage::where('is_archived', false)->with('departments')->orderBy('sequence_order')->get()->groupBy('document_category');

        return view('admin.users', array_merge(
            compact('stagesByCategory'),
            $this->usersTableData($request)
        ));
    }

    /**
     * Fragment refresh for the account list — same live-channel/poll
     * pattern used elsewhere (see ml_training.blade.php's #ml-review-panels).
     * Verification status doesn't broadcast via DocumentRepository::
     * booted()-style model hooks (there's no document involved at all),
     * so without this an admin watching this page would only see the
     * "Unverified" badge disappear on their next manual reload — see
     * AuthController::verifyEmail() firing UserVerified.
     */
    public function usersRefresh(Request $request)
    {
        return view('admin.partials.users_table', $this->usersTableData($request));
    }

    /** Lightweight JSON signal for the poll fallback — see overviewPoll()'s docblock for the same reasoning. */
    public function usersPoll()
    {
        return response()->json([
            'unverified_ids' => User::whereNull('email_verified_at')->pluck('user_id'),
        ]);
    }

    /** @return array{users: \Illuminate\Contracts\Pagination\LengthAwarePaginator, showInactive: bool, inactiveCount: int} */
    private function usersTableData(Request $request): array
    {
        $showInactive = $request->boolean('show_inactive');

        $query = User::with(['createdBy', 'workflowStages'])->orderBy('role');
        if (!$showInactive) {
            $query->where('is_active', true);
        }

        return [
            // Real page route, not the implicit current-request path —
            // also built from within usersRefresh() (the live-poll
            // fragment route); see paginateContainers()'s docblock for
            // the full reasoning.
            'users' => $query->paginate(5)->withQueryString()->withPath(route('admin.users')),
            'showInactive' => $showInactive,
            'inactiveCount' => User::where('is_active', false)->count(),
        ];
    }

    public function storeUser(Request $request)
    {
        $validated = $request->validate([
            'username' => ['required', 'string', 'max:50', 'unique:users,username'],
            'full_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:100', 'unique:users,email'],
            'role' => ['required', 'in:admin,originator,approver'],
            'assigned_category' => [
                'nullable',
                'required_if:role,approver',
                'in:' . implode(',', ValidationService::knownCategories()),
            ],
            'department' => [
                'nullable',
                'required_if:role,approver',
                'in:' . implode(',', User::knownDepartments()),
            ],
            'level' => [
                'nullable',
                'required_if:role,approver',
                'in:' . implode(',', User::knownLevels()),
            ],
            'stage_ids' => ['nullable', 'array'],
            'stage_ids.*' => ['integer', 'exists:workflow_stages,stage_id'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $isApprover = $validated['role'] === 'approver';

        $user = User::create([
            'username' => $validated['username'],
            'full_name' => $validated['full_name'],
            'email' => $validated['email'],
            'role' => $validated['role'],
            // Only Approvers are ever scoped to a category/department/level.
            // Admin and Originator accounts always get null here regardless
            // of what was submitted — Originators upload any document type
            // and are classified automatically, so they are never restricted.
            'assigned_category' => $isApprover ? $validated['assigned_category'] : null,
            'department' => $isApprover ? $validated['department'] : null,
            'level' => $isApprover ? $validated['level'] : null,
            'password_hash' => Hash::make($validated['password']),
            'created_by' => $request->user()->user_id,
            'is_active' => true,
        ]);

        if ($user->role === 'approver' && !empty($validated['stage_ids'])) {
            $validStageIds = $this->stageIdsOwnedByDepartment($user->assigned_category, $user->department, $validated['stage_ids']);
            $user->workflowStages()->sync($validStageIds);
        }

        AuditLog::record($request->user()->user_id, null, 'user_create',
            "Created account #{$user->user_id} ({$user->username}) with role '{$user->role}'" .
            ($user->assigned_category ? ", assigned category '{$user->assigned_category}', department '{$user->department}' ({$user->level})." : '.'));

        // Login is blocked until this is clicked (see AuthController::
        // login()) — sent immediately so the account is usable as soon as
        // its owner checks their inbox, not left silently unusable.
        $user->sendEmailVerificationNotification();

        return back()->with('status', "Account '{$user->username}' created. A verification email was sent to {$user->email}.");
    }

    /**
     * Re-sends the verification email — the only way an unverified account
     * gets a second chance at the link, since the account holder can't log
     * in yet to request it themselves (see AuthController::login()).
     */
    public function resendVerification(User $user)
    {
        abort_if($user->hasVerifiedEmail(), 409, 'This account is already verified.');

        $user->sendEmailVerificationNotification();

        return back()->with('status', "Verification email re-sent to {$user->email}.");
    }

    /** Admin-only: view/edit which specific stages an approver is restricted to. */
    public function editApproverStages(User $user)
    {
        abort_unless($user->role === 'approver', 422, 'Only approver accounts have stage assignments.');

        $stagesByCategory = WorkflowStage::where('is_archived', false)->with('departments')->orderBy('sequence_order')->get()->groupBy('document_category');
        $assignedStageIds = $user->workflowStages()->pluck('workflow_stages.stage_id')->all();

        // Informational only — reassigning category/stages never touches
        // already-created DocumentAssignment rows (their approver_id and
        // sla_expires_at are fixed at routing time and never re-evaluated),
        // so this doesn't block the change. It just tells the admin what's
        // still sitting in this approver's queue before they decide.
        $pendingInOldCategory = DocumentAssignment::pendingFor($user->user_id)->count();

        return view('admin.approver_stages', compact('user', 'stagesByCategory', 'assignedStageIds', 'pendingInOldCategory'));
    }

    /**
     * Updates an approver's category, department, level, and/or which
     * specific stages within them they handle (Feature: Dynamic Workflow
     * Assignment). Changing category OR department always resets stage
     * picks to "every stage the new category/department combination owns"
     * (unrestricted within that) rather than silently carrying over
     * stage_ids that belonged to the old category/department and might not
     * even be legal for the new one. Leaving every checkbox unchecked has
     * the same "unrestricted" effect.
     *
     * Already-created DocumentAssignment rows are untouched by this — see
     * WorkflowService::eligibleApproversForStage(), which only consults
     * assigned_category/department/workflowStages() when routing a NEW
     * document. A pending assignment this approver already holds stays in
     * their queue and can still be decided normally regardless of this
     * change.
     */
    public function updateApproverStages(Request $request, User $user)
    {
        abort_unless($user->role === 'approver', 422, 'Only approver accounts have stage assignments.');

        $validated = $request->validate([
            'assigned_category' => ['required', 'in:' . implode(',', ValidationService::knownCategories())],
            'department' => ['required', 'in:' . implode(',', User::knownDepartments())],
            'level' => ['required', 'in:' . implode(',', User::knownLevels())],
            'stage_ids' => ['nullable', 'array'],
            'stage_ids.*' => ['integer', 'exists:workflow_stages,stage_id'],
        ]);

        $categoryChanged = $validated['assigned_category'] !== $user->assigned_category;
        $departmentChanged = $validated['department'] !== $user->department;
        $resetPicks = $categoryChanged || $departmentChanged;
        $oldCategory = $user->assigned_category;
        $oldDepartment = $user->department;

        // Re-validated server-side against whichever category/department was
        // actually submitted — the dropdowns and stage checkboxes are only
        // kept in sync client-side, so a tampered request could otherwise
        // submit stage IDs from a different category or a department that
        // doesn't own them at all.
        $validStageIds = $this->stageIdsOwnedByDepartment($validated['assigned_category'], $validated['department'], $validated['stage_ids'] ?? []);

        $user->assigned_category = $validated['assigned_category'];
        $user->department = $validated['department'];
        $user->level = $validated['level'];
        $user->save();

        $user->workflowStages()->sync($resetPicks ? [] : $validStageIds);

        $description = $resetPicks
            ? "Reassigned {$user->full_name} (#{$user->user_id}) from '{$oldCategory}'/'{$oldDepartment}' to " .
                "'{$validated['assigned_category']}'/'{$validated['department']}' ({$validated['level']}). " .
                'Stage assignments reset to unrestricted (all stages the new department owns in this category).'
            : "Updated stage assignments for {$user->full_name} (#{$user->user_id}) [{$validated['department']}, {$validated['level']}]: " .
                ($validStageIds->isEmpty() ? 'all stages in category (no restriction).' : implode(', ', $validStageIds->all()));

        AuditLog::record($request->user()->user_id, null, 'assign_stages', $description);

        return redirect()->route('admin.users')->with('status', "Stage assignments updated for {$user->full_name}.");
    }

    /**
     * Server-side integrity gate shared by storeUser() and
     * updateApproverStages(): only stage IDs that both (a) belong to
     * $category and (b) are either unrestricted by department or explicitly
     * owned by $department survive. Mirrors WorkflowService::
     * eligibleApproversForStage()'s own department check exactly, so an
     * approver can never end up holding a stage the admin form wouldn't
     * have let them pick in the first place.
     *
     * @param  array<int>  $requestedStageIds
     */
    private function stageIdsOwnedByDepartment(string $category, ?string $department, array $requestedStageIds): \Illuminate\Support\Collection
    {
        return WorkflowStage::where('document_category', $category)
            ->whereIn('stage_id', $requestedStageIds)
            ->get()
            ->filter(function (WorkflowStage $stage) use ($department) {
                $owners = $stage->departmentNames();
                return $owners === [] || in_array($department, $owners, true);
            })
            ->pluck('stage_id');
    }

    /**
     * Deactivation handoff (Feature): deactivating an approver who's
     * holding pending work resolves each assignment one of three ways —
     * reassigns it to another eligible approver who doesn't already hold
     * their own seat on that stage (see WorkflowService::
     * findReplacementApprover() — rare under the unanimous-approval model,
     * since every eligible approver was normally already seated at routing
     * time), withdraws it with no Admin involvement if a sibling approver
     * already covers that same stage independently (see WorkflowService::
     * withdrawAssignment() — the common case), or — only if genuinely
     * nobody is eligible under the normal category+stage rule — flags it
     * needs_approver and moves it to the separate Unassigned Documents
     * module (see WorkflowService::markNeedsApprover()) rather than the SLA
     * Override Queue, since this was never an SLA failure and shouldn't be
     * recorded as one. is_active is flipped BEFORE this loop runs, not
     * after — otherwise the approver being deactivated could still show up
     * as their own eligible replacement.
     */
    public function toggleUser(Request $request, User $user)
    {
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $reason = $validated['reason'] ?? null;
        $wasActive = $user->is_active;

        $user->is_active = !$wasActive;
        $user->save();

        // Push this the instant it happens, not just via the notification
        // bell — a deactivated user sitting idle on a page should be logged
        // out immediately rather than only finding out on their next click
        // (see the 'account.deactivated' listener in app.js).
        if ($wasActive && !$user->is_active) {
            event(new AccountDeactivated($user->user_id));
        }

        $reassignedCount = 0;
        $withdrawnCount = 0;
        $needsApproverCount = 0;

        if ($wasActive && $user->role === 'approver') {
            $pendingAssignments = DocumentAssignment::where('user_id', $user->user_id)
                ->where('individual_status', 'pending')
                ->where('escalated_to_admin', false)
                ->with(['document', 'stage'])
                ->get();

            foreach ($pendingAssignments as $assignment) {
                $replacement = $this->workflow->findReplacementApprover($assignment);

                if ($replacement) {
                    $this->workflow->reassignAssignment($assignment, $replacement, $user, $reason);
                    $reassignedCount++;
                } elseif ($this->workflow->hasSiblingSeat($assignment)) {
                    $this->workflow->withdrawAssignment($assignment, $user, $reason);
                    $withdrawnCount++;
                } else {
                    $this->workflow->markNeedsApprover($assignment, $user, $reason);
                    $needsApproverCount++;
                }
            }
        }

        AuditLog::record($request->user()->user_id, null, 'user_toggle',
            "Account #{$user->user_id} ({$user->username}) set to " . ($user->is_active ? 'active' : 'inactive') . '.' .
            ($reason ? " Reason: \"{$reason}\"" : '') .
            ($reassignedCount > 0 ? " {$reassignedCount} pending assignment(s) reassigned." : '') .
            ($withdrawnCount > 0 ? " {$withdrawnCount} withdrawn (already covered by another approver on the same stage)." : '') .
            ($needsApproverCount > 0 ? " {$needsApproverCount} moved to Unassigned Documents (no eligible approver)." : ''));

        $status = 'Account status updated.';
        if ($reassignedCount > 0 || $withdrawnCount > 0 || $needsApproverCount > 0) {
            $status .= " {$reassignedCount} reassigned, {$withdrawnCount} withdrawn (already covered), {$needsApproverCount} moved to Unassigned Documents.";
        }

        return back()->with('status', $status);
    }

    // ---------------------------------------------------------------
    // ML dataset training (5–10 sample uploads per category — Scope 1.4)
    // ---------------------------------------------------------------

    private const TRAINING_MIN_PER_CATEGORY = 5;
    // Deliberately no lifetime-total ceiling per category — the corpus is
    // meant to keep growing forever as an admin confirms more documents
    // from the ML Review queue over the system's lifetime (see
    // trainModel()'s trained_in_model_id stamping below for how "already
    // taught the model something" is tracked instead of ever deleting a
    // sample). This is purely a per-REQUEST batch limit — the original
    // reason staging is split by category at all (see stageTrainingSamples()'s
    // docblock) — not a total-staged cap.
    private const TRAINING_BATCH_UPLOAD_LIMIT = 20;
    // Above this word-overlap fraction, a newly staged sample is flagged as
    // a likely near-duplicate of one already staged in the same category
    // (see stageTrainingSamples()). Chosen with headroom above what
    // genuinely different same-category documents naturally share — real,
    // distinct business documents in one category (different department,
    // item, dates) were observed sharing up to ~80% of their vocabulary
    // just from required boilerplate + domain terms; 0.85 flags true
    // near-copies without punishing legitimate variety.
    private const NEAR_DUPLICATE_THRESHOLD = 0.85;
    // Deliberately much stricter than NEAR_DUPLICATE_THRESHOLD above — that
    // one is tuned to be LOOSE (catch near-copies while still letting
    // genuinely different same-category documents through, since those
    // can legitimately share up to ~80% vocabulary). This one decides
    // whether one review decision is allowed to resolve multiple pending
    // documents together (see reviewFlaggedDocument()) — a much higher bar
    // is needed there, since a false-positive match at this stage would
    // silently confirm-and-route (or reject) a document the admin never
    // actually looked at. Not 100%: OCR isn't perfectly deterministic even
    // across two scans/formats of the literal same real document.
    private const EXACT_DUPLICATE_THRESHOLD = 0.97;

    public function mlTraining(Request $request)
    {
        $categories = ValidationService::knownCategories();
        $activeModel = MlModelRepository::active();
        $history = MlModelRepository::orderByDesc('last_trained')->limit(10)->get();

        // Shared across every admin, not scoped to the current session —
        // deliberately so: this app only ever has one active classifier at
        // a time, so there's nothing "personal" about staged samples for
        // it. Storing them in the session tied them to one browser/login
        // and silently lost progress on logout, session expiry, or
        // switching devices; any admin can now pick up where another left
        // off. See the ml_staging_samples migration.
        $stagedSamples = MlStagingSample::with(['stagedBy', 'trainedInModel'])->orderBy('created_at')->get()->groupBy('category');
        $minPerCategory = self::TRAINING_MIN_PER_CATEGORY;
        $batchUploadLimit = self::TRAINING_BATCH_UPLOAD_LIMIT;

        return view('admin.ml_training', array_merge(compact(
            'categories', 'activeModel', 'history', 'stagedSamples', 'minPerCategory', 'batchUploadLimit'
        ), $this->mlReviewQueueData($request)));
    }

    /**
     * Fragment refresh for the Awaiting ML Review / Confirmed From Review
     * panels — same live-channel/poll pattern already used elsewhere (e.g.
     * ArchiveController::refresh(), AdminController::violationsRefresh()).
     * A new low-confidence upload doesn't reach this page via any normal
     * status change on an EXISTING row (see the manual event() calls in
     * WorkflowService::process()/reviewFlaggedDocument() — DocumentRepository
     * ::booted() only fires on an update, never a create), so without this
     * an admin sitting on this page would only see a newly-held document
     * after manually reloading.
     */
    public function mlReviewQueueRefresh(Request $request)
    {
        return view('admin.partials.ml_review_panels', array_merge(
            $this->mlReviewQueueData($request),
            ['categories' => ValidationService::knownCategories()]
        ));
    }

    /**
     * Lightweight JSON signal for the poll fallback — see overviewPoll()'s
     * docblock for the same reasoning. Deliberately reads the FULL
     * unpaginated queues (buildReviewQueueGroups() directly, not
     * mlReviewQueueData()'s paginated 'reviewQueue'/'readabilityQueue') —
     * a change on page 2 of either list still needs to trigger a live
     * refresh even while an admin is sitting on page 1.
     */
    public function mlReviewQueuePoll()
    {
        $priorityThreshold = config('ml.review_priority_threshold', 30);
        $reviewQueue = $this->buildReviewQueueGroups($priorityThreshold);

        // Includes grouped-away "similar" document ids too, not just each
        // group's primary — a new upload that gets absorbed into an
        // EXISTING group wouldn't otherwise change this signal at all
        // (the primary ids stay the same), silently missing a live refresh.
        $pendingIds = $reviewQueue
            ->flatMap(fn ($entry) => [
                $entry->document->document_id,
                ...$entry->similar->pluck('document_id'),
                ...$entry->exactDuplicates->pluck('document_id'),
            ])
            ->all();

        return response()->json([
            'pending_ids' => $pendingIds,
            'confirmed_ids' => DocumentRepository::where('ml_review_status', 'confirmed')
                ->whereNull('ml_recheck_dismissed_at')->pluck('document_id')->all(),
            'readability_pending_ids' => DocumentRepository::where('readability_review_status', 'pending')->pluck('document_id')->all(),
        ]);
    }

    /**
     * @return array{reviewQueue: LengthAwarePaginator, priorityThreshold: int,
     *     stagedFromReview: \Illuminate\Support\Collection, readabilityQueue: LengthAwarePaginator}
     */
    private function mlReviewQueueData(Request $request): array
    {
        $priorityThreshold = config('ml.review_priority_threshold', 30);
        $perPage = 5;

        $reviewQueueFull = $this->buildReviewQueueGroups($priorityThreshold);
        $reviewQueue = $this->paginateContainers($reviewQueueFull, $request, $perPage, route('admin.ml.training'), 'ml_page');

        // Deliberately no near-duplicate grouping here unlike
        // buildReviewQueueGroups() above — a readability hold is
        // already a much rarer event (only fires once a category has
        // enough training data to score against at all), so the extra
        // complexity of bulk-resolving copies hasn't been worth it yet.
        $readabilityQueueFull = DocumentRepository::where('readability_review_status', 'pending')
            ->orderBy('readability_score')
            ->with('originator')
            ->get();
        $readabilityQueue = $this->paginateContainers($readabilityQueueFull, $request, $perPage, route('admin.ml.training'), 'readability_page');

        return [
            'reviewQueue' => $reviewQueue,
            'priorityThreshold' => $priorityThreshold,
            // Documents already confirmed + routed from the review queue,
            // so an admin can "Re-check" them once the model has been
            // retrained on a sample they contributed — see recheckFlaggedDocument().
            // Excludes ones dismissed via the "x" button (see
            // dismissRecheckedDocument()) — a pure UI hide, not a data change.
            'stagedFromReview' => DocumentRepository::where('ml_review_status', 'confirmed')
                ->whereNull('ml_recheck_dismissed_at')
                ->orderByDesc('updated_at')
                ->limit(20)
                ->get(),
            // Compared against each row's confirmed_at_model_id so the
            // view only offers "Re-check" once this has actually changed
            // since confirmation — see recheckFlaggedDocument()'s gate.
            'activeModelId' => MlModelRepository::active()?->model_id,
            'readabilityQueue' => $readabilityQueue,
        ];
    }

    /**
     * Wraps an already-built Collection in a LengthAwarePaginator — shared
     * by every admin queue that groups results into containers before
     * paginating (ML Review, Readability Review, SLA Queue's auto-approved
     * section, Unassigned Documents). $pageName lets two independently
     * paginated lists coexist on the same page/URL (e.g. ML Review +
     * Readability Review both live on ml_training.blade.php) without their
     * ?page= query params colliding.
     *
     * $path is the REAL page route (e.g. route('admin.ml.training')) —
     * deliberately never $request->url(), since every one of these lists
     * is also rendered via a separate .../refresh route for the live-poll
     * JS to swap in place (see e.g. mlReviewQueueRefresh()). Building the
     * path from the current request would bake THAT fragment URL into the
     * Next/Previous links whenever a live swap happens to be what
     * generated this page's markup — clicking one then navigates straight
     * to the bare fragment endpoint (no layout, no CSS) instead of the
     * real page.
     */
    private function paginateContainers(\Illuminate\Support\Collection $items, Request $request, int $perPage, string $path, string $pageName = 'page'): LengthAwarePaginator
    {
        $page = (int) $request->input($pageName, 1);

        return new LengthAwarePaginator(
            $items->forPage($page, $perPage)->values(),
            $items->count(),
            $perPage,
            $page,
            ['path' => $path, 'query' => $request->query(), 'pageName' => $pageName]
        );
    }

    /**
     * Groups pending-review documents so near-identical uploads (the same
     * template submitted by different people) collapse into one row
     * instead of forcing the admin to review five copies of the same
     * thing. Reuses the exact word-overlap check already used to warn
     * about near-duplicate training samples (see stageTrainingSamples()) —
     * same threshold, same reasoning: real distinct documents naturally
     * share a lot of boilerplate, so this only catches true near-copies.
     *
     * @return \Illuminate\Support\Collection<int, object{document: DocumentRepository, similarCount: int}>
     */
    private function buildReviewQueueGroups(int $priorityThreshold): \Illuminate\Support\Collection
    {
        $pending = DocumentRepository::where('ml_review_status', 'pending')
            ->orderBy('ml_confidence')
            ->with('originator')
            ->get();

        $absorbed = [];
        $groups = collect();

        foreach ($pending as $doc) {
            if (in_array($doc->document_id, $absorbed, true)) {
                continue;
            }

            // Two different buckets for two different reasons:
            //  - 'similar' (>= NEAR_DUPLICATE_THRESHOLD, < EXACT_DUPLICATE_THRESHOLD):
            //    grouped purely for display, but still needs its OWN
            //    reachable Confirm/Reject (see ml_review_panels.blade.php's
            //    expandable list) — confirming/rejecting the primary has no
            //    effect on these, and a document with no action of its own
            //    would sit at ml_review_status='pending' forever.
            //  - 'exactDuplicates' (>= EXACT_DUPLICATE_THRESHOLD): genuinely
            //    the same document — reviewFlaggedDocument() resolves these
            //    together with the primary in one decision, so they're
            //    listed here only as a heads-up of what that click will
            //    also affect, not as separately-actionable rows.
            $similar = collect();
            $exactDuplicates = collect();
            foreach ($pending as $other) {
                if ($other->document_id === $doc->document_id || in_array($other->document_id, $absorbed, true)) {
                    continue;
                }
                $similarity = $this->classifier->wordOverlapSimilarity((string) $doc->ocr_text, (string) $other->ocr_text);
                if ($similarity >= self::EXACT_DUPLICATE_THRESHOLD) {
                    $absorbed[] = $other->document_id;
                    $exactDuplicates->push($other);
                } elseif ($similarity >= self::NEAR_DUPLICATE_THRESHOLD) {
                    $absorbed[] = $other->document_id;
                    $similar->push($other);
                }
            }

            $groups->push((object) [
                'document' => $doc,
                'similar' => $similar,
                'exactDuplicates' => $exactDuplicates,
                'isPriority' => (float) $doc->ml_confidence < $priorityThreshold,
            ]);
        }

        return $groups;
    }

    /**
     * Admin confirms (optionally correcting) or rejects a low-confidence
     * document held by WorkflowService::process() — held, not just flagged,
     * because a wrong SVM guess that happens to pass its (wrong) category's
     * validation would otherwise reach the wrong approvers with no clean
     * way to undo an approval after the fact (see process()'s docblock).
     *
     * 'confirm' is the only path that actually routes the document into
     * the workflow (WorkflowService::routeToWorkflow()) — it deliberately
     * requires the admin's own category choice rather than trusting the
     * SVM's uncertain guess as ground truth, since an unconfirmed
     * low-confidence label is exactly the case where that guess is least
     * trustworthy. Also always stages it into the same MlStagingSample pool
     * trainModel() trains from — an admin confirming a category IS the
     * confirmation that it's a good example to learn from, so there's no
     * separate opt-in.
     *
     * 'reject' means the admin could not confirm ANY category fits (bad
     * scan, garbage upload, genuinely ambiguous document) — sets
     * global_status to 'rejected', which is deliberately the same terminal
     * state a rejected-by-approver document reaches, so it reuses the
     * originator's existing resubmit flow rather than needing a new one.
     */
    public function reviewFlaggedDocument(Request $request, DocumentRepository $document)
    {
        abort_unless($document->ml_review_status === 'pending', 404);

        $validated = $request->validate([
            'action' => ['required', 'in:confirm,reject'],
            'category' => ['required_if:action,confirm', 'nullable', Rule::in(ValidationService::knownCategories())],
        ]);

        $admin = $request->user();

        // Checked against the PRIMARY document only — exact-duplicate
        // siblings get resolved as a side effect of this one decision (see
        // below), not individually reviewed, so there's nothing else to
        // time here.
        $minSeconds = config('review.min_review_seconds', 10);
        $secondsReviewed = DocumentReviewSession::secondsSpentSoFar($document->document_id, $admin->user_id);
        abort_if($secondsReviewed < $minSeconds, 422,
            "You need to view the document for at least {$minSeconds} seconds before deciding — {$secondsReviewed}s recorded so far.");

        // Genuinely-identical siblings still pending review — one decision
        // resolves all of them together, since repeating the same call for
        // what is functionally the same document is pure busywork. Each
        // one is still routed through the workflow individually (its own
        // assignment, SLA window, audit trail, notification) — only the
        // manual review step merges, not the actual processing.
        $duplicates = $this->findExactDuplicateSiblings($document);

        if ($validated['action'] === 'reject') {
            $this->rejectReviewedDocument($document, $admin);
            foreach ($duplicates as $duplicate) {
                $this->rejectReviewedDocument($duplicate, $admin);
            }

            $status = "Rejected '{$document->title}'"
                . ($duplicates->isNotEmpty() ? " and {$duplicates->count()} identical document(s) along with it" : '')
                . ' — the originator(s) have been notified to resubmit.';

            return back()->with('status', $status);
        }

        $category = $validated['category'];

        // Only the primary gets staged as a training sample — staging
        // every identical copy too would just trip the near-duplicate
        // warning below against itself, for no benefit to the corpus.
        $duplicateWarning = $this->confirmReviewedDocument($document, $category, $admin, stageForTraining: true);
        foreach ($duplicates as $duplicate) {
            $this->confirmReviewedDocument($duplicate, $category, $admin, stageForTraining: false);
        }

        $status = "Confirmed '{$document->title}' as '{$category}'"
            . ($duplicates->isNotEmpty() ? " and routed {$duplicates->count()} identical document(s) along with it" : '')
            . '.';
        $response = back()->with('status', $status);

        return $duplicateWarning ? $response->with('warning', [$duplicateWarning]) : $response;
    }

    /**
     * Other still-pending documents whose text is a near-exact match of
     * this one (see EXACT_DUPLICATE_THRESHOLD's docblock for why this is a
     * much stricter bar than the display-grouping threshold). Used to let
     * one review decision resolve a whole batch of identical uploads at
     * once — deliberately recomputed fresh from the DB at review time
     * rather than trusting any client-supplied list of ids, since an admin
     * should only ever be able to bulk-resolve documents actually verified
     * server-side to be duplicates of the one they're looking at.
     */
    private function findExactDuplicateSiblings(DocumentRepository $document): \Illuminate\Support\Collection
    {
        return DocumentRepository::where('ml_review_status', 'pending')
            ->where('document_id', '!=', $document->document_id)
            ->get()
            ->filter(fn (DocumentRepository $other) => $this->classifier->wordOverlapSimilarity(
                (string) $document->ocr_text,
                (string) $other->ocr_text
            ) >= self::EXACT_DUPLICATE_THRESHOLD)
            ->values();
    }

    private function rejectReviewedDocument(DocumentRepository $document, User $admin): void
    {
        DocumentReviewSession::closeFor($document, $admin);

        $document->ml_review_status = 'dismissed';
        $document->global_status = 'rejected';
        $document->save();

        AuditLog::record($admin->user_id, $document->document_id, 'ml_review_reject',
            "Rejected '{$document->title}' during ML review — no category could be confidently confirmed " .
            "(originally classified as '{$document->ml_category}' at {$document->ml_confidence}%). Not routed for approval.");

        NotificationRecord::send($document->originator_id, $document->document_id,
            "Your document '{$document->title}' could not be confidently classified by an admin and was not routed for approval. " .
            'Please review it and resubmit a corrected version.');
    }

    /** @return string|null A near-duplicate-in-training-staging warning, only when $stageForTraining. */
    private function confirmReviewedDocument(DocumentRepository $document, string $category, User $admin, bool $stageForTraining): ?string
    {
        DocumentReviewSession::closeFor($document, $admin);

        $duplicateWarning = null;

        // A document can need BOTH classification and readability review at
        // once — if readability was already confirmed first, THAT call
        // already staged this exact document (see confirmReadabilityReview()).
        // Staging it again here would double-count the same text in the
        // training corpus instead of adding a genuinely new example.
        $alreadyStagedByOtherGate = $document->readability_review_status === 'confirmed';

        if ($stageForTraining && !$alreadyStagedByOtherGate) {
            foreach (MlStagingSample::where('category', $category)->get(['original_filename', 'extracted_text']) as $existing) {
                $similarity = $this->classifier->wordOverlapSimilarity((string) $document->ocr_text, $existing->extracted_text);
                if ($similarity >= self::NEAR_DUPLICATE_THRESHOLD) {
                    $duplicateWarning = sprintf(
                        '"%s" looks like a near-duplicate of already-staged "%s" (%d%% word overlap) — staged anyway, but consider whether a more varied example would help more.',
                        $document->title,
                        $existing->original_filename,
                        round($similarity * 100)
                    );
                    break;
                }
            }

            MlStagingSample::create([
                'category' => $category,
                'original_filename' => $document->original_filename ?? $document->title,
                'extracted_text' => (string) $document->ocr_text,
                'staged_by' => $admin->user_id,
            ]);
        }

        $originalCategory = $document->ml_category;
        $originalConfidence = $document->ml_confidence;

        $document->ml_category = $category;
        $document->ml_review_status = 'confirmed';
        // Snapshot of what's active right now — see recheckFlaggedDocument()'s
        // gate: "Re-check" only becomes meaningful once the active model
        // has actually changed since this moment.
        $document->confirmed_at_model_id = MlModelRepository::active()?->model_id;
        $document->save();

        // Only routes once EVERY hold on it has cleared — a document can be
        // pending both this review and the readability review at once (see
        // WorkflowService::ingest()), and confirming one doesn't mean the
        // other has been looked at yet.
        $stillAwaitingReadability = $document->readability_review_status === 'pending';
        if (!$stillAwaitingReadability) {
            $this->workflow->routeToWorkflow($document);
        }

        // ml_review_status changing isn't global_status/disputed_at, so
        // DocumentRepository::booted() won't broadcast this on its own —
        // fire it manually so this document drops off every OTHER admin's
        // review queue live too, not just the acting admin's (who already
        // sees it via this request's own page reload).
        event(new DocumentStatusChanged($document));

        AuditLog::record($admin->user_id, $document->document_id, 'ml_review_confirm',
            "Confirmed '{$document->title}' as '{$category}' (originally classified as '{$originalCategory}' at {$originalConfidence}%)" .
            ($stillAwaitingReadability ? ', still awaiting readability review before it routes.' : ' and routed it for approval.') .
            ($stageForTraining ? ' Added to training staging.' : ' Identical to another document already staged for training in this batch.'));

        NotificationRecord::send($document->originator_id, $document->document_id,
            $stillAwaitingReadability
                ? "Your document '{$document->title}' was confirmed as '{$category}' by an admin, but is still awaiting a separate content readability review before it's routed for approval."
                : "Your document '{$document->title}' was confirmed as '{$category}' by an admin and has been routed for approval.");

        return $duplicateWarning;
    }

    /**
     * Re-runs classification for a document already confirmed by the
     * review queue, against whichever model is active right now —
     * deliberately writes to ml_recheck_* rather than overwriting
     * ml_category/ml_confidence, since those already drove this document's
     * real workflow routing and shouldn't be silently rewritten after the
     * fact. Lets an admin see, concretely, whether retraining on their
     * correction actually improved how this document would score.
     *
     * Gated on the active model having actually changed since this
     * document was confirmed (see confirmed_at_model_id, set in
     * confirmReviewedDocument()) — without this, re-checking before any
     * retrain just re-classifies against the exact same model, producing a
     * meaningless no-op result (identical before/after) that still shows
     * up on the originator's tracking page looking like something happened.
     */
    public function recheckFlaggedDocument(Request $request, DocumentRepository $document)
    {
        abort_unless($document->ml_review_status === 'confirmed', 404);

        $activeModelId = MlModelRepository::active()?->model_id;
        abort_unless($activeModelId !== null && $activeModelId !== $document->confirmed_at_model_id, 409,
            'The model has not been retrained since this document was confirmed — nothing new to check yet.');

        $result = $this->classifier->classify((string) $document->ocr_text);

        $document->ml_recheck_category = $result['category'];
        $document->ml_recheck_confidence = $result['confidence'];
        $document->ml_rechecked_at = now();
        $document->save();

        // ml_recheck_* changing isn't global_status/disputed_at, so
        // DocumentRepository::booted() won't broadcast this on its own —
        // fire it manually, same reasoning as the confirm action, so every
        // admin watching this page sees the new result live, not just
        // whoever clicked "Re-check."
        event(new DocumentStatusChanged($document));

        AuditLog::record($request->user()->user_id, $document->document_id, 'ml_recheck',
            "Re-checked '{$document->title}' against the current model: '{$result['category']}' at {$result['confidence']}% " .
            "(originally '{$document->ml_category}' at {$document->ml_confidence}%).");

        return back()->with('status', "Re-check: '{$result['category']}' at {$result['confidence']}% confidence.");
    }

    /**
     * Dismisses a row from the "Confirmed From Review" panel once an admin
     * has re-checked it and is done watching — a pure UI flag. Deliberately
     * only allowed after a re-check has actually happened (ml_rechecked_at
     * set): dismissing something before ever re-checking it wouldn't fit
     * the intended stage → retrain → re-check → done flow, and the "x"
     * button itself is only rendered once ml_rechecked_at is set (see
     * ml_review_panels.blade.php) — this mirrors that same guard
     * server-side rather than trusting the UI alone.
     */
    public function dismissRecheckedDocument(Request $request, DocumentRepository $document)
    {
        abort_unless($document->ml_review_status === 'confirmed' && $document->ml_rechecked_at !== null, 404);

        $document->ml_recheck_dismissed_at = now();
        $document->save();

        event(new DocumentStatusChanged($document));

        return back()->with('status', "Dismissed '{$document->title}' from the re-check list.");
    }

    /**
     * Admin confirms or rejects a document held ONLY because its
     * readability score fell below the vocabulary threshold (required
     * sections present, word count met — see WorkflowService::ingest() and
     * ValidationService::validate()'s readability_only_failure flag).
     *
     * 'confirm' stages the document into the same MlStagingSample pool the
     * classifier trains from (see confirmReviewedDocument()'s identical
     * reasoning) — an admin confirming this content IS legitimate for its
     * category is exactly what teaches the vocabulary those words for
     * every future document, not just this one.
     *
     * 'reject' sets global_status to 'rejected' — the same terminal state
     * a rejected-by-approver document reaches, so it reuses the
     * originator's existing resubmit flow.
     */
    public function reviewReadability(Request $request, DocumentRepository $document)
    {
        abort_unless($document->readability_review_status === 'pending', 404);

        $validated = $request->validate([
            'action' => ['required', 'in:confirm,reject'],
        ]);

        $admin = $request->user();

        $minSeconds = config('review.min_review_seconds', 10);
        $secondsReviewed = DocumentReviewSession::secondsSpentSoFar($document->document_id, $admin->user_id);
        abort_if($secondsReviewed < $minSeconds, 422,
            "You need to view the document for at least {$minSeconds} seconds before deciding — {$secondsReviewed}s recorded so far.");

        if ($validated['action'] === 'reject') {
            $this->rejectReadabilityReview($document, $admin);

            return back()->with('status', "Rejected '{$document->title}' — the originator has been notified to resubmit.");
        }

        $oldScore = $document->readability_score;
        $this->confirmReadabilityReview($document, $admin);

        return back()->with('status', "Confirmed '{$document->title}' — readability score improved from {$oldScore}% to {$document->readability_score}%.");
    }

    private function rejectReadabilityReview(DocumentRepository $document, User $admin): void
    {
        DocumentReviewSession::closeFor($document, $admin);

        $document->readability_review_status = 'dismissed';
        $document->global_status = 'rejected';
        $document->save();

        AuditLog::record($admin->user_id, $document->document_id, 'readability_review_reject',
            "Rejected '{$document->title}' during content readability review (scored {$document->readability_score}% for " .
            "'{$document->ml_category}'). Not routed for approval.");

        NotificationRecord::send($document->originator_id, $document->document_id,
            "Your document '{$document->title}' did not pass an admin's content readability review and was not routed for approval. " .
            'Please review it and resubmit a corrected version.');
    }

    private function confirmReadabilityReview(DocumentRepository $document, User $admin): void
    {
        DocumentReviewSession::closeFor($document, $admin);

        $category = $document->ml_category;
        $oldScore = $document->readability_score;
        $vocabularyBefore = ValidationService::vocabularySize($category);

        // A document can need BOTH classification and readability review at
        // once — if classification was already confirmed first, THAT call
        // already staged this exact document (see confirmReviewedDocument()).
        // Staging it again here would double-count the same text in the
        // training corpus instead of adding a genuinely new example.
        // $newWords below naturally comes out 0 in that case (vocabulary
        // genuinely didn't change), so no other logic needs adjusting.
        $alreadyStagedByOtherGate = $document->ml_review_status === 'confirmed';

        $duplicateWarning = null;
        if (!$alreadyStagedByOtherGate) {
            foreach (MlStagingSample::where('category', $category)->get(['original_filename', 'extracted_text']) as $existing) {
                $similarity = $this->classifier->wordOverlapSimilarity((string) $document->ocr_text, $existing->extracted_text);
                if ($similarity >= self::NEAR_DUPLICATE_THRESHOLD) {
                    $duplicateWarning = sprintf(
                        '"%s" looks like a near-duplicate of already-staged "%s" (%d%% word overlap) — staged anyway, but consider whether a more varied example would help more.',
                        $document->title,
                        $existing->original_filename,
                        round($similarity * 100)
                    );
                    break;
                }
            }

            MlStagingSample::create([
                'category' => $category,
                'original_filename' => $document->original_filename ?? $document->title,
                'extracted_text' => (string) $document->ocr_text,
                'staged_by' => $admin->user_id,
            ]);
        }

        // Recomputed AFTER staging, against the now-grown vocabulary — this
        // document's own words are trivially all "known" now (it just
        // taught them to itself), which is expected: the meaningful part
        // is that every FUTURE document sharing this vocabulary benefits
        // too, tracked via vocabularyBefore/After below.
        $revalidation = $this->validator->validate($category, (string) $document->ocr_text);
        $vocabularyAfter = ValidationService::vocabularySize($category);

        $document->readability_review_status = 'confirmed';
        $document->readability_score = $revalidation['readability_score'];
        $document->is_validated = true;
        $document->validation_errors = $revalidation['errors'];

        $stillAwaitingClassification = $document->ml_review_status === 'pending';
        $document->global_status = 'classified_validated';
        $document->save();

        if (!$stillAwaitingClassification) {
            $this->workflow->routeToWorkflow($document);
        }

        event(new DocumentStatusChanged($document));

        $newWords = $vocabularyAfter - $vocabularyBefore;
        AuditLog::record($admin->user_id, $document->document_id, 'readability_review_confirm',
            "Confirmed '{$document->title}' during content readability review — score improved from {$oldScore}% to " .
            "{$document->readability_score}% ({$newWords} new term(s) added to the '{$category}' vocabulary)." .
            ($stillAwaitingClassification ? ' Still awaiting classification confidence review before it routes.' : ' Routed for approval.'));

        NotificationRecord::send($document->originator_id, $document->document_id,
            $stillAwaitingClassification
                ? "Your document '{$document->title}' passed an admin's content readability review, but is still awaiting a separate classification confidence review before it's routed for approval."
                : "Your document '{$document->title}' passed an admin's content readability review and has been routed for approval.");

        if ($duplicateWarning) {
            session()->flash('warning', [$duplicateWarning]);
        }
    }

    /**
     * Uploads and text-extracts sample documents for ONE category at a
     * time, accumulating them in a shared table rather than requiring
     * every category's files in a single request. A single combined
     * submission (up to 30 files across 3 categories) can silently exceed
     * PHP's max_file_uploads ini limit (default 20) — files past that
     * cutoff are dropped by PHP itself before Laravel ever sees them, with
     * no error pointing at the real cause. max_file_uploads is
     * PHP_INI_SYSTEM only (no .htaccess/.user.ini/runtime override exists
     * for it), so fixing this by raising the limit isn't an option without
     * root on every future deployment — staging per category (well under
     * any reasonable limit) sidesteps the ceiling entirely instead of
     * depending on it.
     */
    public function stageTrainingSamples(Request $request, string $category)
    {
        abort_unless(in_array($category, ValidationService::knownCategories(), true), 404);

        $validated = $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:' . self::TRAINING_BATCH_UPLOAD_LIMIT],
            'files.*' => ['required', 'file', 'mimes:pdf,txt,docx', 'max:10240'],
        ]);

        // Compared against as each new file is staged, growing to include
        // files from THIS same batch too — so uploading two near-identical
        // files in one request catches the second against the first, not
        // just against whatever was already staged before this request.
        $existingSamples = MlStagingSample::where('category', $category)->get(['original_filename', 'extracted_text']);
        $duplicateWarnings = [];

        foreach ($validated['files'] as $file) {
            $text = $this->extractor->extract($file)['text'];

            foreach ($existingSamples as $existing) {
                $similarity = $this->classifier->wordOverlapSimilarity($text, $existing->extracted_text);
                if ($similarity >= self::NEAR_DUPLICATE_THRESHOLD) {
                    $duplicateWarnings[] = sprintf(
                        '"%s" looks like a near-duplicate of already-staged "%s" (%d%% word overlap) — consider a more varied real example instead.',
                        $file->getClientOriginalName(),
                        $existing->original_filename,
                        round($similarity * 100)
                    );
                    break; // one warning per new file is enough, no need to list every match
                }
            }

            $existingSamples->push(MlStagingSample::create([
                'category' => $category,
                'original_filename' => $file->getClientOriginalName(),
                'extracted_text' => $text,
                'staged_by' => $request->user()->user_id,
            ]));
        }

        $totalStaged = MlStagingSample::where('category', $category)->count();

        $response = back()->with('status', count($validated['files']) . " sample(s) added for '{$category}' ({$totalStaged} total staged).");

        if ($duplicateWarnings) {
            $response->with('warning', $duplicateWarnings);
        }

        return $response;
    }

    public function clearTrainingStaging(Request $request, string $category)
    {
        abort_unless(in_array($category, ValidationService::knownCategories(), true), 404);

        MlStagingSample::where('category', $category)->delete();

        return back()->with('status', "Cleared staged samples for '{$category}'.");
    }

    /** Removes one staged sample without clearing the rest of its category. */
    public function destroyTrainingSample(Request $request, MlStagingSample $sample)
    {
        $sample->delete();

        return back()->with('status', "Removed '{$sample->original_filename}' from staging.");
    }

    public function trainModel(Request $request)
    {
        $categories = ValidationService::knownCategories();
        $stagedSamples = MlStagingSample::orderBy('category')->get()->groupBy('category');

        foreach ($categories as $category) {
            $count = $stagedSamples->get($category, collect())->count();
            abort_if($count < self::TRAINING_MIN_PER_CATEGORY, 422,
                "'{$category}' needs at least " . self::TRAINING_MIN_PER_CATEGORY . " staged samples (has {$count}).");
        }

        $samplesByCategory = $stagedSamples->map(fn ($samples) => $samples->pluck('extracted_text')->all())->all();

        $model = $this->classifier->train($samplesByCategory);

        AuditLog::record($request->user()->user_id, null, 'ml_train',
            "Trained model #{$model->model_id} ({$model->version}) on {$model->training_sample_count} samples across " . count($categories) . ' categories. Estimated accuracy: ' . $model->accuracy_score . '%.');

        // Staged samples deliberately survive training now (no more
        // truncate() here) — an admin can keep adding samples across
        // multiple sessions and have the NEXT training run combine
        // everything staged so far into one larger corpus, rather than
        // every run starting from zero again. Use "Clear" on the ML
        // Training page to explicitly wipe a category's staging if a fresh
        // start is ever actually wanted.
        //
        // Every row gets swept into $samplesByCategory above regardless of
        // category (no per-category filtering happens before train()), so
        // stamping every currently-staged row here is accurate, not an
        // approximation — lets the page show "already taught this model
        // something" vs "still waiting for the next retrain" per sample.
        MlStagingSample::query()->update(['trained_in_model_id' => $model->model_id]);

        return back()->with('status', "Model {$model->version} trained successfully on {$model->training_sample_count} samples (est. accuracy {$model->accuracy_score}%). Staged samples are kept — add more anytime and retrain to combine them.");
    }

    // ---------------------------------------------------------------
    // SLA override queue (Section 5)
    // ---------------------------------------------------------------

    /**
     * SLA Override Queue. Violated assignments are nested the same way as
     * the Approver dashboard: documents an Originator uploaded together in
     * one SubmissionBatch stay grouped under one container so Admins can
     * see at a glance which violation belongs to which original request,
     * rather than a flat list of unrelated-looking rows.
     */
    /** Shared by slaQueue() and slaQueueRefresh() — one place, can't drift. */
    private function slaQueueData(Request $request): array
    {
        $violated = DocumentAssignment::where('escalated_to_admin', true)
            ->whereNull('admin_override_at')
            ->where('individual_status', 'pending')
            ->with(['document.batch', 'document.originator', 'document.assignments.approver', 'stage', 'approver'])
            ->orderBy('sla_expires_at')
            ->get();

        $containers = $violated
            ->groupBy(fn (DocumentAssignment $a) => $a->document->batch_id ? 'batch-' . $a->document->batch_id : 'doc-' . $a->document_id)
            ->map(function ($groupAssignments) {
                $first = $groupAssignments->first();
                $batch = $first->document->batch;

                return (object) [
                    'is_batch' => (bool) $batch,
                    'batch' => $batch,
                    'due_date' => $batch->due_date ?? $first->document->due_date,
                    'originator' => $first->document->originator,
                    'documents' => $groupAssignments->groupBy('document_id'),
                ];
            })
            ->sortBy(fn ($c) => $c->due_date)
            ->values();

        $perPage = 2;
        $page = (int) $request->input('page', 1);

        $assignments = new \Illuminate\Pagination\LengthAwarePaginator(
            $containers->forPage($page, $perPage)->values(),
            $containers->count(),
            $perPage,
            $page,
            // Real page route, not $request->url() — see paginateContainers()'s
            // docblock for why: this data is also built from within
            // slaQueueRefresh() (a separate .../refresh route for the
            // live-poll JS), and a path derived from THAT request would
            // bake the bare-fragment URL into Next/Previous whenever a
            // live swap happens to be what rendered this page.
            ['path' => route('admin.sla.queue'), 'query' => $request->query()]
        );

        // Grouped by document, same reasoning as $containers above: a
        // document can have MORE than one auto-approved stage awaiting
        // review at once (e.g. Budget Check and Final Approval both fired),
        // and a flat per-stage list made that look like unrelated rows.
        $reviewAssignments = DocumentAssignment::where('auto_approved', true)
            ->whereNull('admin_reviewed_at')
            ->with(['document', 'stage', 'approver'])
            ->get();

        $reviewContainers = $reviewAssignments
            ->groupBy('document_id')
            ->map(fn ($stageAssignments) => (object) [
                'document' => $stageAssignments->first()->document,
                'assignments' => $stageAssignments->sortBy(fn ($a) => $a->stage->sequence_order)->values(),
            ])
            ->sortBy(fn ($c) => $c->assignments->first()->acted_at)
            ->values();

        // Distinct pageName from the escalated section above — both lists
        // live on the same sla_queue.blade.php page/URL, so a shared
        // 'page' query param would make paging one silently page the other.
        $reviewContainers = $this->paginateContainers($reviewContainers, $request, 2, route('admin.sla.queue'), 'auto_approved_page');

        return [$assignments, $reviewContainers];
    }

    public function slaQueue(Request $request)
    {
        [$assignments, $reviewContainers] = $this->slaQueueData($request);

        return view('admin.sla_queue', compact('assignments', 'reviewContainers'));
    }

    /** Live-refresh fragment (Feature: realtime) — same data as slaQueue(), just the results. */
    public function slaQueueRefresh(Request $request)
    {
        [$assignments, $reviewContainers] = $this->slaQueueData($request);

        return view('admin.partials.sla-queue-results', compact('assignments', 'reviewContainers'));
    }

    /** Cheap change-signal for the live-poll fallback — same pattern as overviewPoll(). */
    public function slaQueuePoll()
    {
        return response()->json([
            'violated' => DocumentAssignment::where('escalated_to_admin', true)->whereNull('admin_override_at')->where('individual_status', 'pending')->count(),
            'awaiting_review' => DocumentAssignment::where('auto_approved', true)->whereNull('admin_reviewed_at')->count(),
        ]);
    }

    /**
     * Section 5 follow-up: review every stage the SYSTEM auto-approved on
     * ONE document, all at once — an admin reviews the document as a
     * whole, not stage-by-stage (a document can have more than one
     * auto-approved stage awaiting review, e.g. Budget Check AND Final
     * Approval both firing). Confirming just leaves a note on each.
     * Disputing does NOT reverse the approval(s) — there is no "reopen"
     * path in WorkflowService::completeStage(), and unwinding an
     * already-finalized document (possibly already notified/archived) is
     * unsafe — instead it sets disputed_at once (global_status is left
     * as-is, so the document's approval history stays intact) and asks the
     * originator to resubmit a corrected version.
     */
    public function reviewAutoApproval(Request $request, DocumentRepository $document)
    {
        $pending = DocumentAssignment::where('document_id', $document->document_id)
            ->where('auto_approved', true)
            ->whereNull('admin_reviewed_at')
            ->with('stage')
            ->get();

        abort_if($pending->isEmpty(), 404);

        $validated = $request->validate([
            'outcome' => ['required', 'in:confirmed,disputed'],
            'note' => ['required_if:outcome,disputed', 'nullable', 'string', 'max:1000'],
        ]);

        $admin = $request->user();
        $note = $validated['note'] ?? null;
        $stageNames = $pending->pluck('stage.stage_name')->all();

        foreach ($pending as $assignment) {
            $assignment->admin_reviewed_at = now();
            $assignment->admin_reviewed_by = $admin->user_id;
            $assignment->admin_review_note = $note;
            $assignment->admin_review_outcome = $validated['outcome'];
            $assignment->save();
        }

        $stageList = implode(', ', $stageNames);

        if ($validated['outcome'] === 'confirmed') {
            AuditLog::record($admin->user_id, $document->document_id, 'admin_review',
                "Confirmed auto-approved stage(s) '{$stageList}'." . ($note ? " Note: \"{$note}\"" : ''));

            return back()->with('status', 'Marked as reviewed.');
        }

        $document->disputed_at = now();
        $document->save();

        AuditLog::record($admin->user_id, $document->document_id, 'admin_dispute',
            "Disputed auto-approved stage(s) '{$stageList}': \"{$note}\"");

        NotificationRecord::send($document->originator_id, $document->document_id,
            "Your document '{$document->title}' was auto-approved by the system, but an Admin has disputed it: \"{$note}\". Please resubmit a corrected version.", 'high');

        if ($document->originator->email) {
            Mail::to($document->originator->email)->queue(new AutoApprovalDisputedMail($document, $stageNames, $note));
        }

        foreach (User::where('role', 'admin')->where('is_active', true)->where('user_id', '!=', $admin->user_id)->get() as $other) {
            NotificationRecord::send($other->user_id, $document->document_id,
                "{$admin->full_name} disputed the system's auto-approval of '{$document->title}': \"{$note}\".", 'high');
        }

        return back()->with('status', 'Disputed — the originator has been notified to resubmit.');
    }

    public function override(Request $request, DocumentAssignment $assignment)
    {
        $validated = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            // Same rule as the approver's own decide() — a reject with no
            // explanation leaves the originator nothing to act on.
            'comments' => [Rule::requiredIf(fn () => $request->input('decision') === 'rejected'), 'nullable', 'string', 'max:1000'],
        ]);

        $minSeconds = config('review.min_review_seconds', 10);
        $secondsReviewed = DocumentReviewSession::secondsSpentSoFar($assignment->document_id, $request->user()->user_id);
        abort_if($secondsReviewed < $minSeconds, 422,
            "You need to view the document for at least {$minSeconds} seconds before deciding — {$secondsReviewed}s recorded so far.");

        $this->sla->adminOverride($assignment, $request->user(), $validated['decision'], $validated['comments'] ?? null);

        return back()->with('status', 'Override applied: ' . ucfirst($validated['decision']) . '.');
    }

    /**
     * Overrides every violated stage assigned to the SAME approver for one
     * document in a single action — mirrors
     * ApprovalController::decideBatch() so the SLA queue doesn't show one
     * override form per stage when a single approver is holding more than
     * one violated stage for the same document.
     */
    public function overrideBatch(Request $request)
    {
        $validated = $request->validate([
            'assignment_ids' => ['required', 'array', 'min:1'],
            'assignment_ids.*' => ['integer', 'exists:document_assignments,assignment_id'],
            'decision' => ['required', 'in:approved,rejected'],
            'comments' => [Rule::requiredIf(fn () => $request->input('decision') === 'rejected'), 'nullable', 'string', 'max:1000'],
        ]);

        $assignments = DocumentAssignment::whereIn('assignment_id', $validated['assignment_ids'])
            ->where('escalated_to_admin', true)
            ->whereNull('admin_override_at')
            ->where('individual_status', 'pending')
            ->get();

        abort_if($assignments->isEmpty(), 409, 'These assignments have already been actioned.');

        $minSeconds = config('review.min_review_seconds', 10);
        $skippedUnreviewed = 0;

        foreach ($assignments as $assignment) {
            $assignment->refresh();
            if ($assignment->individual_status !== 'pending') {
                continue; // already closed as a side effect of an earlier iteration (e.g. rejection cascade)
            }
            if (DocumentReviewSession::secondsSpentSoFar($assignment->document_id, $request->user()->user_id) < $minSeconds) {
                $skippedUnreviewed++;
                continue;
            }
            $this->sla->adminOverride($assignment, $request->user(), $validated['decision'], $validated['comments'] ?? null);
        }

        $status = 'Override applied: ' . ucfirst($validated['decision']) . '.';
        if ($skippedUnreviewed > 0) {
            $status .= " {$skippedUnreviewed} assignment(s) were skipped — you need to view the document for at least {$minSeconds} seconds before deciding.";
        }

        return back()->with('status', $status);
    }

    // ---------------------------------------------------------------
    // Unassigned Documents — seats deactivation left with genuinely no
    // eligible approver (strict category+stage match — see WorkflowService
    // ::markNeedsApprover()). Deliberately separate from the SLA Override
    // Queue: these were never an SLA failure, so they must never be
    // recorded as one.
    // ---------------------------------------------------------------

    /** Shared by unassignedDocuments() and unassignedDocumentsRefresh() — one place, can't drift. */
    private function unassignedDocumentsData(Request $request): LengthAwarePaginator
    {
        $containers = DocumentAssignment::where('needs_approver', true)
            ->where('individual_status', 'pending')
            // Once its own deadline lapses, a needs_approver seat escalates
            // just like any other (see SlaService::escalate()) and moves
            // to the SLA Override Queue instead — it shouldn't still show
            // here once that's happened.
            ->where('escalated_to_admin', false)
            ->with(['document.originator', 'stage', 'reassignedFrom'])
            ->orderBy('needs_approver_at')
            ->get()
            ->groupBy('document_id')
            ->map(fn ($stageAssignments) => (object) [
                'document' => $stageAssignments->first()->document,
                'assignments' => $stageAssignments->sortBy(fn ($a) => $a->stage->sequence_order)->values(),
            ])
            ->sortBy(fn ($c) => $c->assignments->first()->needs_approver_at)
            ->values();

        return $this->paginateContainers($containers, $request, 2, route('admin.unassigned.index'));
    }

    public function unassignedDocuments(Request $request)
    {
        $containers = $this->unassignedDocumentsData($request);

        return view('admin.unassigned_documents', compact('containers'));
    }

    public function unassignedDocumentsRefresh(Request $request)
    {
        $containers = $this->unassignedDocumentsData($request);

        return view('admin.partials.unassigned-documents', compact('containers'));
    }

    /** Cheap change-signal for the live-poll fallback — same pattern as overviewPoll(). */
    public function unassignedDocumentsPoll()
    {
        return response()->json([
            'count' => DocumentAssignment::where('needs_approver', true)->where('individual_status', 'pending')->count(),
        ]);
    }

    public function decideUnassigned(Request $request, DocumentAssignment $assignment)
    {
        abort_if(!$assignment->needs_approver || $assignment->individual_status !== 'pending', 409, 'This assignment has already been actioned.');

        $validated = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'comments' => [Rule::requiredIf(fn () => $request->input('decision') === 'rejected'), 'nullable', 'string', 'max:1000'],
        ]);

        $minSeconds = config('review.min_review_seconds', 10);
        $secondsReviewed = DocumentReviewSession::secondsSpentSoFar($assignment->document_id, $request->user()->user_id);
        abort_if($secondsReviewed < $minSeconds, 422,
            "You need to view the document for at least {$minSeconds} seconds before deciding — {$secondsReviewed}s recorded so far.");

        $this->workflow->adminDecideUnassigned($assignment, $request->user(), $validated['decision'], $validated['comments'] ?? null);

        return back()->with('status', 'Decision applied: ' . ucfirst($validated['decision']) . '.');
    }

    // ---------------------------------------------------------------
    // Workflow stage configuration
    // ---------------------------------------------------------------

    /**
     * Feature: toggle whether an approver's Approve/Reject is restricted to
     * business hours (see ApprovalController::requireBusinessHoursIfEnforced()).
     * Off by default on a fresh install — an Admin opts in deliberately.
     * A plain checkbox toggle, not AJAX — this is a rare, deliberate
     * configuration change, not something that needs a live-updating UI.
     */
    public function updateBusinessHoursEnforcement(Request $request)
    {
        $setting = SystemSetting::current();
        $setting->enforce_business_hours_decisions = $request->boolean('enforce_business_hours_decisions');
        $setting->updated_by = $request->user()->user_id;
        $setting->save();

        \App\Events\SystemSettingsChanged::dispatch();

        return back()->with('status', $setting->enforce_business_hours_decisions
            ? 'Approver decisions are now restricted to business hours (9 AM–5 PM, Mon–Sat).'
            : 'Approver decisions are no longer restricted to business hours.');
    }

    public function workflowConfig()
    {
        $stages = WorkflowStage::orderBy('document_category')->orderBy('sequence_order')->get()->groupBy('document_category');
        $categories = ValidationService::knownCategories();

        // Section 2: orphan-prevention data — how many PENDING assignments
        // (blocks archive/delete) vs. any assignment ever (blocks hard
        // delete; forces archive instead) each stage has.
        $activeCounts = DocumentAssignment::where('individual_status', 'pending')
            ->select('stage_id')->selectRaw('count(*) as cnt')->groupBy('stage_id')->pluck('cnt', 'stage_id');
        $historyCounts = DocumentAssignment::select('stage_id')->selectRaw('count(*) as cnt')->groupBy('stage_id')->pluck('cnt', 'stage_id');

        // The actual pending assignments blocking archive/delete, so the
        // Admin can resolve each one directly (approve/reject on the
        // approver's behalf via the same SlaService::adminOverride() used
        // by the SLA Override Queue) instead of reassigning the document
        // to a stage its approver isn't actually eligible for.
        $pendingByStage = DocumentAssignment::where('individual_status', 'pending')
            ->with('document')
            ->get()
            ->groupBy('stage_id');

        $businessHoursEnforced = SystemSetting::current()->enforce_business_hours_decisions;

        return view('admin.workflow_config', compact('stages', 'categories', 'activeCounts', 'historyCounts', 'pendingByStage', 'businessHoursEnforced'));
    }

    /** Live-refresh fragment (Feature: realtime) — same stage-list data, just the results panel. */
    public function workflowConfigRefresh()
    {
        $stages = WorkflowStage::orderBy('document_category')->orderBy('sequence_order')->get()->groupBy('document_category');

        $activeCounts = DocumentAssignment::where('individual_status', 'pending')
            ->select('stage_id')->selectRaw('count(*) as cnt')->groupBy('stage_id')->pluck('cnt', 'stage_id');
        $historyCounts = DocumentAssignment::select('stage_id')->selectRaw('count(*) as cnt')->groupBy('stage_id')->pluck('cnt', 'stage_id');

        $pendingByStage = DocumentAssignment::where('individual_status', 'pending')
            ->with('document')
            ->get()
            ->groupBy('stage_id');

        return view('admin.partials.workflow-config-results', compact('stages', 'activeCounts', 'historyCounts', 'pendingByStage'));
    }

    /** Cheap change-signal for the live-poll fallback — same pattern as overviewPoll(). */
    public function workflowConfigPoll()
    {
        return response()->json([
            'stages' => WorkflowStage::count(),
            'pending' => DocumentAssignment::where('individual_status', 'pending')->count(),
        ]);
    }

    public function storeStage(Request $request)
    {
        $validated = $request->validate([
            'document_category' => ['required', 'in:' . implode(',', ValidationService::knownCategories())],
            'stage_name' => ['required', 'string', 'max:255'],
            'sequence_order' => ['required', 'integer', 'min:1', 'max:20'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $stage = WorkflowStage::create($validated);

        AuditLog::record($request->user()->user_id, null, 'workflow_config',
            "Added workflow stage '{$stage->stage_name}' for '{$stage->document_category}' (order {$stage->sequence_order}).");

        return back()->with('status', 'Workflow stage saved.');
    }

    public function updateStage(Request $request, WorkflowStage $stage)
    {
        $validated = $request->validate([
            'stage_name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $oldName = $stage->stage_name;
        $stage->update($validated);

        AuditLog::record($request->user()->user_id, null, 'workflow_config',
            "Renamed/edited workflow stage #{$stage->stage_id} ('{$stage->stage_name}').");

        $this->notifyApproversOfStageChange($stage, "updated the '{$oldName}' stage's details to '{$stage->stage_name}'");

        return back()->with('status', 'Stage updated.');
    }

    /**
     * Confirms to every approver currently holding a pending assignment on
     * this stage that an edit/archive already happened. In-app only,
     * purely a record of what occurred — does not block or delay the
     * Admin's action. See notifyPendingApprovers() for the ADVANCE notice
     * sent before the Admin acts, which is the one meant to actually give
     * the approver a chance to review first.
     */
    private function notifyApproversOfStageChange(WorkflowStage $stage, string $what): void
    {
        $approverIds = DocumentAssignment::where('stage_id', $stage->stage_id)
            ->where('individual_status', 'pending')
            ->distinct()
            ->pluck('user_id');

        foreach ($approverIds as $approverId) {
            NotificationRecord::send($approverId, null,
                "An Admin {$what} — you have a pending document on this stage; nothing about your task itself changed, but the stage details did.");
        }
    }

    /**
     * Section 3/4: sent BEFORE the Admin edits or archives a stage — an
     * explicit, separate action the Admin triggers to give each affected
     * approver a heads-up and a real chance to review/act on their own
     * pending document(s) first, rather than the Admin immediately
     * overriding them via "Review & decide pending". High priority since
     * it's time-sensitive; does not itself change or block anything —
     * the Admin decides when enough time has passed to proceed.
     */
    public function notifyPendingApprovers(Request $request, WorkflowStage $stage)
    {
        $pending = DocumentAssignment::where('stage_id', $stage->stage_id)
            ->where('individual_status', 'pending')
            ->with('document')
            ->get();

        abort_if($pending->isEmpty(), 409, 'No pending assignments on this stage to notify about.');

        foreach ($pending->unique('user_id') as $assignment) {
            NotificationRecord::send($assignment->user_id, null,
                "Heads up: an Admin is planning to edit or archive the '{$stage->stage_name}' stage soon. " .
                "Please review and act on your pending document(s) for it as soon as you can, before the Admin steps in on your behalf.",
                'high');
        }

        AuditLog::record($request->user()->user_id, null, 'workflow_config',
            "Notified " . $pending->unique('user_id')->count() . " approver(s) with pending work on stage '{$stage->stage_name}' ahead of a planned edit/archive.");

        return back()->with('status', 'Approver(s) notified — give them time to review before editing or archiving.');
    }

    public function moveStageUp(Request $request, WorkflowStage $stage)
    {
        $this->swapStageOrder($request, $stage, 'up');
        return back();
    }

    public function moveStageDown(Request $request, WorkflowStage $stage)
    {
        $this->swapStageOrder($request, $stage, 'down');
        return back();
    }

    private function swapStageOrder(Request $request, WorkflowStage $stage, string $direction): void
    {
        $neighbor = WorkflowStage::where('document_category', $stage->document_category)
            ->where('is_archived', false)
            ->where('stage_id', '!=', $stage->stage_id)
            ->where('sequence_order', $direction === 'up' ? '<=' : '>=', $stage->sequence_order)
            ->orderBy('sequence_order', $direction === 'up' ? 'desc' : 'asc')
            ->first();

        if (!$neighbor) {
            return;
        }

        [$a, $b] = [$stage->sequence_order, $neighbor->sequence_order];
        $stage->update(['sequence_order' => $b]);
        $neighbor->update(['sequence_order' => $a]);

        AuditLog::record($request->user()->user_id, null, 'workflow_config', "Reordered stage '{$stage->stage_name}'.");
    }

    public function archiveStage(Request $request, WorkflowStage $stage)
    {
        abort_if($this->stageHasActiveAssignments($stage), 409,
            'This stage has active (pending) assignments. Resolve them first — see "Review & decide pending" below.');

        $this->notifyApproversOfStageChange($stage, "archived the '{$stage->stage_name}' stage");

        $stage->update(['is_archived' => true]);

        AuditLog::record($request->user()->user_id, null, 'workflow_config', "Archived stage '{$stage->stage_name}'.");

        return back()->with('status', 'Stage archived.');
    }

    public function unarchiveStage(Request $request, WorkflowStage $stage)
    {
        $stage->update(['is_archived' => false]);

        AuditLog::record($request->user()->user_id, null, 'workflow_config', "Unarchived stage '{$stage->stage_name}'.");

        return back()->with('status', 'Stage unarchived.');
    }

    public function destroyStage(Request $request, WorkflowStage $stage)
    {
        abort_if($this->stageHasActiveAssignments($stage), 409,
            'This stage has active (pending) assignments. Resolve them first — see "Review & decide pending" below.');

        abort_if(DocumentAssignment::where('stage_id', $stage->stage_id)->exists(), 409,
            'This stage has historical assignment history and cannot be permanently deleted — archive it instead.');

        $name = $stage->stage_name;
        $stage->delete();

        AuditLog::record($request->user()->user_id, null, 'workflow_config', "Deleted unused stage '{$name}'.");

        return back()->with('status', 'Stage deleted.');
    }

    private function stageHasActiveAssignments(WorkflowStage $stage): bool
    {
        return DocumentAssignment::where('stage_id', $stage->stage_id)->where('individual_status', 'pending')->exists();
    }

    // ---------------------------------------------------------------
    // Operational Window Controls & Holiday Management (Section 1)
    // ---------------------------------------------------------------

    public function calendar(Request $request)
    {
        $month = $request->filled('month') ? Carbon::parse($request->string('month') . '-01') : now()->startOfMonth();

        $holidays = SlaHoliday::whereBetween('holiday_date', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])
            ->get()
            ->keyBy(fn (SlaHoliday $h) => $h->holiday_date->toDateString());

        return view('admin.calendar', compact('month', 'holidays'));
    }

    /** Live-refresh fragment (Feature: realtime) — same grid data for the currently-viewed month, just the results. */
    public function calendarRefresh(Request $request)
    {
        $month = $request->filled('month') ? Carbon::parse($request->string('month') . '-01') : now()->startOfMonth();

        $holidays = SlaHoliday::whereBetween('holiday_date', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])
            ->get()
            ->keyBy(fn (SlaHoliday $h) => $h->holiday_date->toDateString());

        return view('admin.partials.calendar-grid', compact('month', 'holidays'));
    }

    /** Cheap change-signal for the live-poll fallback, scoped to the visible month — same pattern as overviewPoll(). */
    public function calendarPoll(Request $request)
    {
        $month = $request->filled('month') ? Carbon::parse($request->string('month') . '-01') : now()->startOfMonth();

        $holidays = SlaHoliday::whereBetween('holiday_date', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()]);

        return response()->json([
            'count' => (clone $holidays)->count(),
            'latest' => (clone $holidays)->max('updated_at'),
        ]);
    }

    public function storeHoliday(Request $request)
    {
        $validated = $request->validate([
            'holiday_date' => ['required', 'date', 'unique:sla_holidays,holiday_date'],
            'label' => ['nullable', 'string', 'max:255'],
        ]);

        SlaHoliday::create($validated + ['created_by' => $request->user()->user_id]);

        AuditLog::record($request->user()->user_id, null, 'sla_holiday_add', "Marked {$validated['holiday_date']} as a non-working day.");

        // Section 1: a newly-marked holiday must (a) push forward the due
        // date of any in-flight document that was already using that day
        // as its hard deadline, and (b) retroactively recalculate every
        // already-routed pending assignment's SLA window that spans it —
        // both are otherwise "computed once, stored statically" at
        // routing/submission time and would silently stay wrong.
        $sync = $this->workflow->syncDueDatesWithCalendar();

        return back()->with('status', 'Holiday added.' . $this->calendarSyncSummary($sync));
    }

    public function destroyHoliday(Request $request, SlaHoliday $holiday)
    {
        $date = $holiday->holiday_date->toDateString();
        $holiday->delete();

        AuditLog::record($request->user()->user_id, null, 'sla_holiday_remove', "Unmarked {$date} as a non-working day.");

        // Removing a holiday only ever frees up time — it can't invalidate
        // an existing due date — but SLA windows still need re-syncing
        // since more business time may now be available before the
        // (unchanged) due date than was assumed when they were computed.
        $changed = $this->workflow->recalculatePendingSlaDeadlines();

        return back()->with('status', 'Holiday removed.' . ($changed ? " {$changed} pending assignment(s) had their SLA deadline recalculated." : ''));
    }

    private function calendarSyncSummary(array $sync): string
    {
        $parts = [];
        if ($sync['documents_shifted'] > 0) {
            $parts[] = "{$sync['documents_shifted']} document(s) had their due date moved off a now-non-working day";
        }
        if ($sync['assignments_recalculated'] > 0) {
            $parts[] = "{$sync['assignments_recalculated']} pending assignment(s) had their SLA deadline recalculated";
        }

        return $parts ? ' ' . implode('; ', $parts) . '.' : '';
    }

    // ---------------------------------------------------------------
    // SLA Violation reporting (Section 4)
    // ---------------------------------------------------------------

    public function violationsReport(Request $request)
    {
        $query = $this->violationsQuery($request);

        // Same "folders first" pattern as the Archive (Feature: browse by
        // category). The stat cards, the approver-roster control, the
        // filter form, and the results list are ALL gated behind picking a
        // category (or searching) — an unfiltered "Top Category: Job
        // Order" card (or a roster full of real violation counts) on first
        // load reads as if the report already defaulted to Job Order, so
        // none of that computes/shows until something's actually picked.
        // Only the folder tiles themselves (each showing its own count)
        // render on the bare landing screen.
        $hasActiveFilters = $request->filled('category') || $request->filled('document')
            || $request->filled('date_from') || $request->filled('date_to');
        $showFolders = !$hasActiveFilters;

        $violations = $showFolders ? null : (clone $query)
            ->with(['document', 'approver', 'assignment.adminOverrideBy', 'assignment.approver'])
            ->orderByDesc('violation_timestamp')->paginate(5)->withQueryString()
            // Real page route, not the implicit current-request path —
            // also built from within violationsRefresh() (the live-poll
            // fragment route); see paginateContainers()'s docblock for
            // the full reasoning.
            ->withPath(route('admin.sla.violations'));

        return view('admin.sla_violations', array_merge($this->violationStats($query, $request), [
            'showFolders' => $showFolders,
            'folders' => $showFolders ? $this->violationFolderStats() : null,
            'violations' => $violations,
            'categories' => ValidationService::knownCategories(),
        ]));
    }

    /**
     * Live search (Feature: instant results as you type) — identical
     * query/pagination as violationsReport()'s results branch, via the
     * shared helpers below, returning just the results fragment.
     */
    public function violationsRefresh(Request $request)
    {
        $violations = (clone $this->violationsQuery($request))
            ->with(['document', 'approver', 'assignment.adminOverrideBy', 'assignment.approver'])
            ->orderByDesc('violation_timestamp')->paginate(5)->withQueryString()
            // Real page route, not the implicit current-request path —
            // also built from within violationsRefresh() (the live-poll
            // fragment route); see paginateContainers()'s docblock for
            // the full reasoning.
            ->withPath(route('admin.sla.violations'));

        return view('admin.partials.violations_results', compact('violations'));
    }

    /**
     * Cheap change-signal for the live-poll fallback — scoped to the SAME
     * filters currently applied (category/document/date range), same
     * reasoning as Document Tracking's poll: an unrelated category's new
     * violation shouldn't trigger a swap of a filtered view that wouldn't
     * even show it.
     */
    public function violationsPoll(Request $request)
    {
        $query = $this->violationsQuery($request);

        return response()->json([
            'count' => (clone $query)->count(),
            'latest' => (clone $query)->max('violation_timestamp'),
        ]);
    }

    private function violationsQuery(Request $request)
    {
        $query = SlaViolation::query();

        if ($request->filled('document')) {
            $term = $request->string('document');
            $query->whereHas('document', fn ($q) => $q->where('title', 'like', "%{$term}%"));
        }
        if ($request->filled('category')) {
            $category = $request->string('category');
            $query->whereHas('document', fn ($q) => $q->where('ml_category', $category));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('violation_timestamp', '>=', $request->date('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('violation_timestamp', '<=', $request->date('date_to'));
        }

        return $query;
    }

    /** One row per category for the folder-grid landing screen. */
    private function violationFolderStats()
    {
        return collect(ValidationService::knownCategories())->map(fn ($category) => (object) [
            'category' => $category,
            'total' => SlaViolation::whereHas('document', fn ($q) => $q->where('ml_category', $category))->count(),
        ]);
    }

    /**
     * Everything the stat cards + approver roster need. Still computed
     * regardless of $showFolders, but the view only renders either of them
     * once $showFolders is false (see violationsReport()) — on the bare
     * folder screen this result is simply unused rather than wired to
     * anything, since nothing on that screen needs it yet.
     */
    private function violationStats($query, Request $request): array
    {
        $byApprover = (clone $query)->selectRaw('approver_id, count(*) as total')
            ->groupBy('approver_id')->with('approver')->orderByDesc('total')->limit(5)->get();

        $byStage = (clone $query)->selectRaw('stage_name, count(*) as total')
            ->groupBy('stage_name')->orderByDesc('total')->limit(5)->get();

        // Top Category — parallels Top Approver/Top Bottleneck Stage, and
        // ties directly into the category-folder browsing above.
        $byCategory = (clone $query)
            ->join('document_repository', 'sla_violations.document_id', '=', 'document_repository.document_id')
            ->selectRaw('document_repository.ml_category, count(*) as total')
            ->groupBy('document_repository.ml_category')
            ->orderByDesc('total')
            ->first();

        // Disputed — how many of these violations were later flagged by an
        // Admin as a bad auto-approval (see AdminController::
        // reviewAutoApproval()). Otherwise only visible per-row as a badge,
        // never as a total anywhere on this page.
        $disputedCount = (clone $query)->whereHas('document', fn ($q) => $q->whereNotNull('disputed_at'))->count();

        $totalCount = (clone $query)->count();
        $avgOverdue = (clone $query)->avg('duration_overdue');

        // Full roster for the "Top Approver" card's expanded view — EVERY
        // approver, not just the ones with violations, so a clean record is
        // visible too, not just a leaderboard of offenders. violation_count
        // respects the same filters as the rest of this report (so
        // narrowing the date range/category above narrows this too);
        // assignment_count is unfiltered by date (a lifetime total) so
        // "0 violations" can be read against "0 of 0 assignments" (never
        // given work yet) vs "0 of 50" (a genuinely clean record).
        $approverRoster = User::where('role', 'approver')
            ->withCount([
                'slaViolations as violation_count' => function ($q) use ($request) {
                    if ($request->filled('document')) {
                        $term = $request->string('document');
                        $q->whereHas('document', fn ($dq) => $dq->where('title', 'like', "%{$term}%"));
                    }
                    if ($request->filled('category')) {
                        $category = $request->string('category');
                        $q->whereHas('document', fn ($dq) => $dq->where('ml_category', $category));
                    }
                    if ($request->filled('date_from')) {
                        $q->whereDate('violation_timestamp', '>=', $request->date('date_from'));
                    }
                    if ($request->filled('date_to')) {
                        $q->whereDate('violation_timestamp', '<=', $request->date('date_to'));
                    }
                },
                'assignmentsAsApprover as assignment_count' => function ($q) use ($request) {
                    if ($request->filled('category')) {
                        $category = $request->string('category');
                        $q->whereHas('document', fn ($dq) => $dq->where('ml_category', $category));
                    }
                },
            ])
            ->orderByDesc('violation_count')
            ->orderBy('full_name')
            ->get();

        // Per-approver breakdown by category, for the roster's nested
        // reveal. Not redundant with assigned_category: approvers can be
        // reassigned to a different category over time (see
        // AdminController::updateApproverStages()), but a SlaViolation
        // records the category the DOCUMENT was in at violation time, not the
        // approver's current assignment — so someone reassigned mid-tenure
        // can legitimately have violation history split across categories
        // that the roster's single lumped total would otherwise hide.
        $byApproverCategory = (clone $query)
            ->join('document_repository', 'sla_violations.document_id', '=', 'document_repository.document_id')
            ->selectRaw('sla_violations.approver_id, document_repository.ml_category, count(*) as total')
            ->groupBy('sla_violations.approver_id', 'document_repository.ml_category')
            ->orderByDesc('total')
            ->get()
            ->groupBy('approver_id');

        return [
            'byApprover' => $byApprover,
            'approverRoster' => $approverRoster,
            'byApproverCategory' => $byApproverCategory,
            'byStage' => $byStage,
            'byCategory' => $byCategory,
            'disputedCount' => $disputedCount,
            'totalCount' => $totalCount,
            'avgOverdue' => round($avgOverdue ?? 0),
        ];
    }

    // ---------------------------------------------------------------
    // Audit trail viewer (Section 6)
    // ---------------------------------------------------------------

    /**
     * Every document-linked audit entry (upload, classify, validate,
     * route, approve, stage_complete, ...) used to get its own top-level
     * row — with several dozen entries per document, that buried the
     * actual "what happened to this document" question under a wall of
     * near-duplicate rows, especially once the nested "Movements" panel
     * already showed the exact same data in one place. Now each document
     * collapses to ONE row, anchored to its upload (or legacy import), and
     * the full history lives in the expandable panel underneath — same
     * data, once, not scattered across N rows. System-level entries with
     * no document (workflow config, SLA settings, account changes, ML
     * training) have nothing to collapse into, so they stay as their own
     * rows exactly as before, interleaved chronologically with the
     * document rows.
     *
     * The Action/Employees/date filters shift meaning to match: instead
     * of matching one row's own action_type/user_id/timestamp, they now
     * ask "does this document's history contain a matching entry
     * anywhere" — filtering "rejected" surfaces every document that was
     * rejected at some point, not a single rejected-labeled row.
     */
    /**
     * The document-row + system-row building/filtering logic shared by
     * auditLogs() (full page) and auditLogsRefresh() (live-poll fragment)
     * — kept in exactly one place so a live-swapped table can never drift
     * from what a normal page load would have shown for the same filters.
     */
    private function buildAuditRows(Request $request): \Illuminate\Support\Collection
    {
        $documentTerm = null;
        $numericId = null;
        if ($request->filled('document')) {
            // Matches either a document title substring or, if the term
            // looks numeric (with or without a leading "#"), the exact
            // document_id — so "47" or "#47" both find it directly
            // without needing to know/guess the title.
            $documentTerm = trim($request->string('document'));
            $numericId = ltrim($documentTerm, '#');
        }

        $documentRows = DocumentRepository::with('originator')
            ->when($documentTerm !== null, function ($q) use ($documentTerm, $numericId) {
                $q->where(function ($q2) use ($documentTerm, $numericId) {
                    $q2->where('title', 'like', "%{$documentTerm}%");
                    if ($numericId !== '' && ctype_digit($numericId)) {
                        $q2->orWhere('document_id', (int) $numericId);
                    }
                });
            })
            ->when($request->filled('action_type'), fn ($q) => $q->whereHas(
                'auditLogs', fn ($q2) => $q2->where('action_type', $request->string('action_type'))
            ))
            ->when($request->filled('actor_id'), function ($q) use ($request) {
                $actorId = $request->integer('actor_id');
                $q->where(function ($q2) use ($actorId) {
                    $q2->where('originator_id', $actorId)
                        ->orWhereHas('auditLogs', fn ($q3) => $q3->where('user_id', $actorId));
                });
            })
            ->when($request->filled('date_from'), function ($q) use ($request) {
                $from = $request->date('date_from');
                $q->where(function ($q2) use ($from) {
                    $q2->whereDate('upload_date', '>=', $from)
                        ->orWhereHas('auditLogs', fn ($q3) => $q3->whereDate('timestamp', '>=', $from));
                });
            })
            ->when($request->filled('date_to'), function ($q) use ($request) {
                $to = $request->date('date_to');
                $q->where(function ($q2) use ($to) {
                    $q2->whereDate('upload_date', '<=', $to)
                        ->orWhereHas('auditLogs', fn ($q3) => $q3->whereDate('timestamp', '<=', $to));
                });
            })
            ->get()
            ->map(fn (DocumentRepository $doc) => (object) [
                'kind' => 'document',
                'sort_at' => $doc->upload_date,
                'document' => $doc,
            ]);

        // A document-name search has nothing meaningful to match against a
        // system-level entry (no document at all) — skip fetching them
        // entirely rather than returning a query that can never match.
        $systemRows = $documentTerm !== null ? collect() : AuditLog::with('user')
            ->whereNull('document_id')
            ->when($request->filled('action_type'), fn ($q) => $q->where('action_type', $request->string('action_type')))
            ->when($request->filled('actor_id'), fn ($q) => $q->where('user_id', $request->integer('actor_id')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('timestamp', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('timestamp', '<=', $request->date('date_to')))
            ->get()
            ->map(fn (AuditLog $log) => (object) [
                'kind' => 'system',
                'sort_at' => $log->timestamp,
                'log' => $log,
            ]);

        return $documentRows->concat($systemRows)->sortByDesc('sort_at')->values();
    }

    private function paginateAuditRows(Request $request): \Illuminate\Pagination\LengthAwarePaginator
    {
        $rows = $this->buildAuditRows($request);

        $perPage = 13;
        $page = (int) $request->input('page', 1);

        return new \Illuminate\Pagination\LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            // Real page route, not $request->url() — this is also built
            // from within auditLogsRefresh() (the live-poll fragment
            // route); see paginateContainers()'s docblock for the full
            // reasoning.
            ['path' => route('admin.audit.logs'), 'query' => $request->query()]
        );
    }

    public function auditLogs(Request $request)
    {
        $logs = $this->paginateAuditRows($request);

        $actionTypes = AuditLog::select('action_type')->distinct()->orderBy('action_type')->pluck('action_type');
        $actors = User::orderBy('full_name')->get(['user_id', 'full_name']);

        return view('admin.audit_logs', compact('logs', 'actionTypes', 'actors'));
    }

    /**
     * Renders just the results fragment (resources/views/admin/partials/
     * audit-results.blade.php) for the live-poll JS to swap into place —
     * see audit_logs.blade.php. Respects the same filters/page as a
     * normal load (the JS forwards the current query string), so a live
     * update never silently drops an active filter or jumps the admin
     * back to page 1.
     */
    public function auditLogsRefresh(Request $request)
    {
        $logs = $this->paginateAuditRows($request);

        return view('admin.partials.audit-results', compact('logs'));
    }

    /**
     * Lightweight JSON endpoint the audit log page's JS polls as a
     * fallback if the WebSocket connection is down — same "just the
     * latest AuditLog id" signal already proven for the admin dashboard's
     * own poll (see overviewPoll()), reused here rather than inventing a
     * second cheap-signal shape.
     */
    public function auditLogsPoll()
    {
        return response()->json(['latest_log_id' => AuditLog::max('log_id')]);
    }

    // ---------------------------------------------------------------
    // Document Tracking module
    // ---------------------------------------------------------------

    /**
     * Every document ever submitted, in one place, permanently — unlike
     * Archive (approved documents only) or the SLA queue (violated
     * assignments only), nothing here is ever filtered out by outcome and
     * nothing is ever removed once a document finishes. Each row links to
     * the same tracking page (<x-workflow-stage-list> +
     * <x-document-movement-timeline>) used elsewhere, so "every movement,
     * who reviewed it, who approved/rejected it and why" is answerable
     * for any document at any time.
     */
    private function buildDocumentTrackingQuery(Request $request)
    {
        return DocumentRepository::with(['originator', 'assignments.approver', 'assignments.stage'])
            ->when($request->filled('document'), function ($q) use ($request) {
                $term = trim($request->string('document'));
                $numericId = ltrim($term, '#');
                $q->where(function ($q2) use ($term, $numericId) {
                    $q2->where('title', 'like', "%{$term}%");
                    if ($numericId !== '' && ctype_digit($numericId)) {
                        $q2->orWhere('document_id', (int) $numericId);
                    }
                });
            })
            ->when($request->filled('category'), fn ($q) => $q->where('ml_category', $request->string('category')))
            ->when($request->filled('status'), fn ($q) => $q->where('global_status', $request->string('status')))
            ->when($request->filled('originator_id'), fn ($q) => $q->where('originator_id', $request->integer('originator_id')))
            ->orderByDesc('upload_date');
    }

    public function documents(Request $request)
    {
        $documents = $this->buildDocumentTrackingQuery($request)->paginate(5)->withQueryString()
            // Real page route, not the implicit current-request path —
            // this is also built from within documentsRefresh() (the
            // live-poll fragment route); see paginateContainers()'s
            // docblock for the full reasoning.
            ->withPath(route('admin.documents.index'));

        $categories = WorkflowStage::select('document_category')->distinct()->orderBy('document_category')->pluck('document_category');
        $originators = User::where('role', 'originator')->orderBy('full_name')->get(['user_id', 'full_name']);

        return view('admin.documents.index', compact('documents', 'categories', 'originators'));
    }

    /**
     * Fragment for the live-poll JS to swap in place — see
     * admin/documents/index.blade.php. Same reasoning as
     * auditLogsRefresh(): respects the current filters/page so a live
     * update never drops an active filter or resets pagination.
     */
    public function documentsRefresh(Request $request)
    {
        $documents = $this->buildDocumentTrackingQuery($request)->paginate(5)->withQueryString()
            // Real page route, not the implicit current-request path —
            // this is also built from within documentsRefresh() (the
            // live-poll fragment route); see paginateContainers()'s
            // docblock for the full reasoning.
            ->withPath(route('admin.documents.index'));

        return view('admin.partials.documents-results', compact('documents'));
    }

    /** Same cheap "latest AuditLog id" signal as auditLogsPoll() — a new upload, decision, or review event all write one. */
    public function documentsPoll()
    {
        return response()->json(['latest_log_id' => AuditLog::max('log_id')]);
    }
}