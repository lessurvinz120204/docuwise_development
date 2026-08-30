<?php

namespace App\Http\Controllers;

use App\Models\DocumentRepository;
use App\Models\DocumentReviewSession;
use App\Models\SubmissionBatch;
use App\Rules\ReliableMimeType;
use App\Services\ValidationService;
use App\Services\WorkflowService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class DocumentController extends Controller
{
    public function __construct(private WorkflowService $workflow)
    {
    }

    /**
     * The originator's filtered/paginated submissions query — shared by
     * dashboard() (full page), refresh() (the AJAX fragment the live-poll
     * JS swaps in), and poll() (the cheap "did anything change" signal),
     * so all three always agree on what's currently visible for a given
     * set of filters.
     */
    private function documentsQuery(Request $request, int $userId)
    {
        $query = DocumentRepository::forOriginator($userId)
            ->with(['currentAssignment.stage', 'assignments.stage', 'batch']);

        if ($request->filled('document')) {
            $query->where('title', 'like', '%' . $request->string('document') . '%');
        }
        if ($request->filled('status')) {
            $query->where('global_status', $request->string('status'));
        }
        if ($request->filled('category')) {
            $query->where('ml_category', $request->string('category'));
        }

        return $query;
    }

    /** Originator dashboard: drag-drop upload + live tracking list (DFD 3.1-3.4, 4.0). */
    public function dashboard(Request $request)
    {
        $documents = $this->documentsQuery($request, $request->user()->user_id)
            ->latest('upload_date')->paginate(5)->withQueryString()
            // Real page route, not the implicit current-request path —
            // this is also built from within refresh() (the live-poll
            // fragment route); see AdminController::paginateContainers()'s
            // docblock for the full reasoning.
            ->withPath(route('originator.dashboard'));

        $categories = ValidationService::knownCategories();

        return view('originator.dashboard', compact('documents', 'categories'));
    }

    /**
     * Renders just the submissions fragment (originator/partials/submissions.blade.php)
     * for the dashboard's live-poll JS to swap in place — see
     * resources/js/app.js's startLivePoll() and dashboard.blade.php for why
     * this beats a full page reload. Respects the same filters as a normal
     * page load (the JS forwards the current query string).
     */
    public function refresh(Request $request)
    {
        $documents = $this->documentsQuery($request, $request->user()->user_id)
            ->latest('upload_date')->paginate(5)->withQueryString()
            // Real page route, not the implicit current-request path —
            // this is also built from within refresh() (the live-poll
            // fragment route); see AdminController::paginateContainers()'s
            // docblock for the full reasoning.
            ->withPath(route('originator.dashboard'));

        return view('originator.partials.submissions', compact('documents'));
    }

    /**
     * Lightweight JSON endpoint the dashboard's JS polls every ~5-10s. A
     * plain row *count* alone would miss an existing document's status
     * changing (processing -> approved, say) without any row being
     * added/removed, so this also reports the newest `updated_at` across
     * the filtered set — either changing is enough to trigger a live swap.
     */
    public function poll(Request $request)
    {
        $query = $this->documentsQuery($request, $request->user()->user_id);

        return response()->json([
            'count' => (clone $query)->count(),
            'latest_update' => $query->max('updated_at'),
        ]);
    }

    /**
     * Accepts one or more files in a single submission. Every file in the
     * request is linked to one new SubmissionBatch and shares the same
     * due date, so Approvers and Admins see them nested together as one
     * approval request instead of as unrelated flat rows (Feature: grouped
     * dashboards).
     */
    public function store(Request $request)
    {
        // Explicit validation on every document metadata input (Section 3).
        // Content-based MIME verification is layered on top of mimes:, but
        // deliberately only for the formats that sniff reliably across
        // OS/Office versions (pdf/png/jpg/txt) — legacy .doc (OLE2) and
        // .docx (zip) sniff inconsistently enough that a strict mimetype
        // check would false-reject legitimate Word files, so those stay
        // protected by the mimes: extension-mapping rule only (with
        // WorkflowService::ingest()'s extraction_failed handling as a
        // downstream backstop for garbage content that slips through).
        // "files.0", "files.1", etc. are meaningless to a user picking 20
        // files at once — swap in each file's own original name so a
        // failure reads "'weird_scan.heic' must be a file of type: ..."
        // instead of "The files.0 field must be...", which gives no way to
        // tell which of the selected files actually failed.
        $fileAttributeNames = [];
        foreach ($request->file('files', []) as $index => $file) {
            if ($file) {
                $fileAttributeNames["files.{$index}"] = "'" . $file->getClientOriginalName() . "'";
            }
        }

        $validated = $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:20'],
            'files.*' => [
                'file',
                'mimes:pdf,docx,doc,txt,png,jpg,jpeg',
                new ReliableMimeType(),
                'max:20480',
            ],
            'due_date' => ['required', 'date', function ($attribute, $value, $fail) {
                $buffer = config('sla.min_due_date_buffer_minutes', 15);
                if (Carbon::parse($value)->lt(now()->addMinutes($buffer))) {
                    $fail("The due date must be at least {$buffer} minutes from now.");
                    return;
                }
                // Section 1 (extended): reject a due date outside working
                // hours/days outright rather than silently moving it — see
                // WorkflowService::isDueDateWithinWorkingHours()'s docblock.
                if (!$this->workflow->isDueDateWithinWorkingHours($value)) {
                    $fail('The due date must fall within working hours (9 AM–5 PM, Mon–Sat, excluding holidays). Please pick a valid date and time.');
                }
            }],
            'requires_printing' => ['sometimes', 'boolean'],
        ], [], $fileAttributeNames);

        $effectiveDueDate = Carbon::parse($validated['due_date']);
        // Unchecked means "no explicit override" — WorkflowService::ingest()
        // still applies the category's own default once classification
        // determines it, this only ever adds the requirement, never
        // removes one the category itself calls for.
        $requiresPrinting = $request->boolean('requires_printing');

        $batch = SubmissionBatch::create([
            'originator_id' => $request->user()->user_id,
            'due_date' => $effectiveDueDate,
        ]);

        // Per-file try/catch, not a single .map() — each ingest() call
        // already has its own DB::transaction and commits independently, so
        // a genuinely unexpected failure (not the known extraction/
        // classification failure modes, which ingest() now handles
        // gracefully on its own) on file 3 of 5 must not take down the
        // request and silently leave the user wondering why only 2 of their
        // 5 files show up, with no explanation and a raw 500 page.
        $documents = collect();
        $failedFiles = [];

        foreach ($validated['files'] as $file) {
            try {
                $documents->push($this->workflow->ingest(
                    $file, $request->user(), $effectiveDueDate->toDateTimeString(), $batch->batch_id, null, $requiresPrinting
                ));
            } catch (Throwable $e) {
                Log::error('Unexpected failure ingesting an uploaded document', [
                    'originator_id' => $request->user()->user_id,
                    'file' => $file->getClientOriginalName(),
                    'exception' => $e->getMessage(),
                ]);
                $failedFiles[] = $file->getClientOriginalName();
            }
        }

        $status = $this->buildSubmissionStatusMessage($documents, $failedFiles);

        return redirect()
            ->route('originator.dashboard')
            ->with('status', $status);
    }

    private function buildSubmissionStatusMessage($documents, array $failedFiles = []): string
    {
        $failureNote = $failedFiles
            ? ' ' . count($failedFiles) . ' file(s) hit an unexpected error and were not uploaded (' .
                implode(', ', $failedFiles) . ') — please try re-uploading just those.'
            : '';

        if ($documents->isEmpty()) {
            return 'Your upload could not be processed due to an unexpected error. Please try again.' . $failureNote;
        }

        if ($documents->count() === 1) {
            $document = $documents->first();
            return ($document->is_validated
                ? "'{$document->title}' uploaded, classified as '{$document->ml_category}', and routed for approval."
                : "'{$document->title}' uploaded but failed validation — see details below.") . $failureNote;
        }

        $failedValidation = $documents->reject(fn ($d) => $d->is_validated)->count();

        return "{$documents->count()} documents uploaded together and routed as one approval request." .
            ($failedValidation > 0 ? " {$failedValidation} failed validation — see details below." : '') . $failureNote;
    }

    public function show(Request $request, DocumentRepository $document)
    {
        $this->authorize('viewTracking', $document);

        $document->load(['assignments.stage', 'assignments.approver', 'auditLogs.user', 'previousVersion', 'nextVersion']);

        return view('originator.tracking', compact('document'));
    }

    /**
     * Renders just the tracking page body (originator/partials/tracking-content.blade.php)
     * for that page's live-poll JS to swap in place — see
     * resources/js/app.js's startLiveChannel()/startLivePoll() and
     * tracking.blade.php for why this beats a full page reload. Reacts to
     * ANY stage on this document being decided, not just the document's
     * overall global_status finalizing — see DocumentAssignment::booted().
     */
    public function trackingRefresh(Request $request, DocumentRepository $document)
    {
        $this->authorize('viewTracking', $document);

        $document->load(['assignments.stage', 'assignments.approver', 'auditLogs.user', 'previousVersion', 'nextVersion']);

        return view('originator.partials.tracking-content', compact('document'));
    }

    /**
     * Lightweight JSON endpoint this document's tracking page polls every
     * ~5-10s as a fallback if the WebSocket connection is down. Reports
     * global_status, the newest audit-log timestamp, and a hash of every
     * assignment's individual_status — any of the three changing (a stage
     * being decided mid-pipeline doesn't necessarily change global_status
     * or add an audit-log row alone) is enough to trigger a live swap.
     */
    public function trackingPoll(Request $request, DocumentRepository $document)
    {
        $this->authorize('viewTracking', $document);

        return response()->json([
            'status' => $document->global_status,
            'latest_audit' => $document->auditLogs()->max('timestamp'),
            'assignment_statuses' => $document->assignments()->pluck('individual_status', 'assignment_id'),
        ]);
    }

    /**
     * Section 5 (extended): a rejected document was previously a dead end —
     * the only way to try again was uploading an entirely new, unrelated
     * document. This re-runs the same ingest pipeline (extract, classify,
     * validate, route) on a revised file, but links the result back to the
     * rejected document as the next entry in its version chain instead.
     */
    public function resubmit(Request $request, DocumentRepository $document)
    {
        $this->authorize('resubmit', $document);
        abort_unless($document->global_status === 'rejected', 409, 'Only a rejected document can be resubmitted.');

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,docx,doc,txt,png,jpg,jpeg', new ReliableMimeType(), 'max:20480'],
            'due_date' => ['required', 'date', function ($attribute, $value, $fail) {
                $buffer = config('sla.min_due_date_buffer_minutes', 15);
                if (Carbon::parse($value)->lt(now()->addMinutes($buffer))) {
                    $fail("The due date must be at least {$buffer} minutes from now.");
                    return;
                }
                if (!$this->workflow->isDueDateWithinWorkingHours($value)) {
                    $fail('The due date must fall within working hours (9 AM–5 PM, Mon–Sat, excluding holidays). Please pick a valid date and time.');
                }
            }],
        ]);

        $effectiveDueDate = Carbon::parse($validated['due_date']);

        try {
            $newDocument = $this->workflow->ingest(
                $validated['file'],
                $request->user(),
                $effectiveDueDate->toDateTimeString(),
                null, // resubmissions stand alone, not re-attached to the original's (possibly already-resolved) batch
                $document,
                $document->requires_printing, // carries forward rather than asking again on every resubmission
            );
        } catch (Throwable $e) {
            Log::error('Unexpected failure ingesting a resubmitted document', [
                'originator_id' => $request->user()->user_id,
                'original_document_id' => $document->document_id,
                'file' => $validated['file']->getClientOriginalName(),
                'exception' => $e->getMessage(),
            ]);

            return redirect()->route('originator.documents.show', $document)
                ->with('status', 'Your resubmission could not be processed due to an unexpected error. Please try again.');
        }

        $status = $newDocument->is_validated
            ? "Resubmitted as version {$newDocument->version_number}, classified as '{$newDocument->ml_category}', and routed for approval."
            : "Resubmitted as version {$newDocument->version_number}, but failed validation — see details below.";

        return redirect()->route('originator.documents.show', $newDocument)->with('status', $status);
    }

    /**
     * Streams the exact original uploaded file inline (not force-download)
     * for the embedded viewer on the Approver dashboard. Distinct from
     * ArchiveController::download(), which forces a "Save As" download of
     * already-approved documents; this is for reviewing a file still in
     * progress, unaltered from what the originator submitted.
     */
    public function viewFile(Request $request, DocumentRepository $document)
    {
        $user = $request->user();

        $this->authorize('viewFile', $document);

        // A review session opens for anyone with a legitimate reviewing
        // stake — any approver or admin — while the document as a whole
        // hasn't been judged yet, not narrowly gated to "is it THIS
        // person's specific seat pending right now." That narrower rule
        // used to mean a second approver whose seat wasn't the currently-
        // active one, or an admin just looking without the document being
        // escalated to them specifically, never got tracked at all — so
        // the "currently reviewing" presence cluster only ever showed one
        // of several simultaneous viewers. See countsAsActiveReviewer()
        // for the exact rule; presence()/presenceLeave() below apply the
        // identical condition so opening and closing stay symmetric — an
        // Originator (never an approver or admin) still never gets a
        // session, and once the document IS judged, nobody accumulates a
        // fresh one just from revisiting it in Archive/Decision History.
        if ($this->countsAsActiveReviewer($document, $user)) {
            DocumentReviewSession::openFor($document, $user);
        }

        // Default disk (config('filesystems.default')), not hardcoded
        // 'local' — respects FILESYSTEM_DISK. Storage::response() (unlike
        // response()->file(), which needs an actual local filesystem path
        // and therefore ONLY ever worked against local disk) streams
        // correctly from any configured disk driver, including an
        // S3-compatible one like Cloudflare R2.
        abort_unless(Storage::exists($document->file_path), 404, 'File not found.');

        $mime = $document->mime_type ?: 'application/octet-stream';

        $response = Storage::response($document->file_path, $document->original_filename ?? $document->title, [
            'Content-Type' => $mime,
        ]);

        // no-store, not whatever the disk driver's response() defaults to:
        // a browser silently serving a second open from cache skips this
        // whole method entirely, which means openFor() never runs — no
        // review session gets created for that visit, so it never shows up
        // as "reviewing", never gets a realtime push, and its time never
        // gets counted. Every open of this popup needs to genuinely hit the
        // server, not just the first one.
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->addCacheControlDirective('must-revalidate');
        $response->headers->removeCacheControlDirective('public');

        return $response;
    }

    /**
     * Polled every few seconds by the document viewer modal while it's
     * open — returns who's currently reviewing this document (any
     * approver or admin with a legitimate stake, see
     * countsAsActiveReviewer()) and, if the requester is one of them,
     * heartbeats their own session so it stays inside the live() window.
     * Stopping the poll (closing the modal, navigating away, a dead tab)
     * means no more heartbeats, so the icon disappears for everyone else
     * within ~40s without needing an explicit "I closed the viewer"
     * signal.
     */
    public function presence(Request $request, DocumentRepository $document)
    {
        $user = $request->user();

        $this->authorize('viewFile', $document);

        if ($this->countsAsActiveReviewer($document, $user)) {
            DocumentReviewSession::heartbeat($document, $user);
        }

        $viewers = DocumentReviewSession::live()
            ->where('document_id', $document->document_id)
            ->with('user')
            ->get()
            ->unique('user_id')
            ->values()
            ->map(fn ($session) => [
                'name' => $session->user->full_name ?? 'Unknown',
                'role' => ucfirst($session->user->role ?? ''),
                'category' => $session->user->assigned_category,
            ]);

        return response()->json(['viewers' => $viewers]);
    }

    /**
     * Explicit "I closed the viewer" beacon — fired once on modal close
     * or tab unload (see document-viewer-modal.blade.php). Reuses the
     * same closeFor() a real decision uses (not a separate presence-only
     * mechanism): this both makes the presence icon disappear for other
     * viewers instantly (stillOpen() fails the moment closed_at is set,
     * so it drops out of live() for free) AND correctly records
     * duration_seconds for this review pass, so closing the popup without
     * deciding no longer silently loses that time from the running total
     * — reopening later now counts as a genuine second review pass
     * instead.
     */
    public function presenceLeave(Request $request, DocumentRepository $document)
    {
        $user = $request->user();

        // Previously had no authorization check at all — any authenticated
        // approver/admin could fire this beacon against any document,
        // closing a review session they had no real access to. Same
        // 'viewFile' ability as viewFile()/presence() above, kept symmetric
        // with them per this method's own docblock.
        $this->authorize('viewFile', $document);

        if ($this->countsAsActiveReviewer($document, $user)) {
            DocumentReviewSession::closeFor($document, $user);
        }

        return response()->noContent();
    }

    /**
     * Whether $user opening $document right now counts as an active
     * review session (drives DocumentReviewSession::openFor()/heartbeat()/
     * closeFor() in viewFile()/presence()/presenceLeave() above) —
     * anyone with a genuine reviewing stake (any approver, or an admin)
     * while the document as a whole hasn't been judged yet. Deliberately
     * NOT narrowed to "is it specifically this person's seat pending
     * right now" — that used to mean a second approver whose seat wasn't
     * the currently-active one, or an admin looking at a document that
     * hadn't happened to escalate to them, never got tracked at all, so
     * the "currently reviewing" presence cluster only ever showed one of
     * several simultaneous viewers. An Originator (never true for either
     * isApprover() or isAdmin()) never counts here — the presence
     * cluster is about who's reviewing it, not who owns it. Once the
     * document IS judged (approved/rejected/auto_approved), nobody
     * accumulates a fresh session just from revisiting it afterward.
     */
    private function countsAsActiveReviewer(DocumentRepository $document, $user): bool
    {
        if (!$user->isApprover() && !$user->isAdmin()) {
            return false;
        }

        return !in_array($document->global_status, ['approved', 'rejected', 'auto_approved'], true);
    }
}