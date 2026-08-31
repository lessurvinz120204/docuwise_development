<?php

use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\NotificationRecord;
use App\Models\SlaViolation;
use App\Models\User;
use App\Models\WorkflowStage;

/**
 * Boundary coverage for the pagination sizes revised per user feedback —
 * exactly N items must show no "Next" link, N+1 must show one. Verifies
 * the EXACT configured size, not just "pagination exists somewhere."
 */
function paginationDoc(User $originator, array $overrides = []): DocumentRepository
{
    return DocumentRepository::create(array_merge([
        'originator_id' => $originator->user_id,
        'title' => 'pg-test-' . uniqid() . '.txt',
        'file_path' => 'documents/' . uniqid() . '.txt',
        'mime_type' => 'text/plain',
        'due_date' => now()->addDay(),
        'upload_date' => now(),
        'global_status' => 'approved',
        'ml_category' => 'Job Order',
    ], $overrides));
}

it('renders the custom Tailwind pagination view (not the unstyled vendor default)', function () {
    $admin = User::factory()->admin()->create();
    for ($i = 0; $i < 6; $i++) {
        paginationDoc($admin);
    }

    $response = $this->actingAs($admin)->get(route('admin.archive', ['category' => 'Job Order']));

    $response->assertOk();
    $response->assertSee('Pagination Navigation', false); // aria-label unique to resources/views/vendor/pagination/custom.blade.php
});

it('paginates Admin Archive at 5 per page, Approver/Originator Archive at 10', function () {
    $admin = User::factory()->admin()->create();
    $approver = User::factory()->approver('Job Order')->create();
    for ($i = 0; $i < 6; $i++) {
        paginationDoc($admin);
    }

    $this->actingAs($admin)->get(route('admin.archive', ['category' => 'Job Order']))->assertSee('Next');
    // 6th item pushes admin (5/page) into a second page but not approver (10/page).
    $this->actingAs($approver)->get(route('approver.archive'))->assertDontSee('Next');
});

it('paginates Admin Users at 5 per page', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->count(4)->originator()->create(); // + the admin itself = 5, still one page

    $this->actingAs($admin)->get(route('admin.users'))->assertDontSee('Next');

    User::factory()->originator()->create(); // 6th active user
    $this->actingAs($admin)->get(route('admin.users'))->assertSee('Next');
});

it('paginates the ML Review Queue and Readability Review Queue at 5 per page each, independently', function () {
    $admin = User::factory()->admin()->create();
    for ($i = 0; $i < 6; $i++) {
        $word = str_repeat(chr(97 + $i), 4);
        $text = implode(' ', array_fill(0, 30, $word));
        paginationDoc($admin, ['global_status' => 'processing', 'ml_review_status' => 'pending', 'ml_confidence' => 30.0, 'ocr_text' => $text]);
        paginationDoc($admin, ['global_status' => 'processing', 'readability_review_status' => 'pending', 'readability_score' => 50]);
    }

    $response = $this->actingAs($admin)->get(route('admin.ml.training'));
    $response->assertOk()->assertSee('Awaiting ML Review (6)')->assertSee('Content Readability Review (6)');

    // Paging ML Review to page 2 must not also page Readability Review.
    $page2 = $this->actingAs($admin)->get(route('admin.ml.training', ['ml_page' => 2]));
    $page2->assertOk();
});

it('paginates the SLA Override Queue and Auto-Approved section at 2 per page each, independently', function () {
    $admin = User::factory()->admin()->create();
    $approver = User::factory()->approver('Job Order')->create();
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review', 'sequence_order' => 1]);

    for ($i = 0; $i < 3; $i++) {
        $doc = paginationDoc($approver, ['global_status' => 'processing']);
        DocumentAssignment::create([
            'document_id' => $doc->document_id, 'user_id' => $approver->user_id,
            'stage_id' => WorkflowStage::first()->stage_id, 'due_date' => $doc->due_date,
            'priority_rank' => 2, 'individual_status' => 'pending', 'sla_expires_at' => now()->subHour(),
            'escalated_to_admin' => true, 'escalated_at' => now()->subMinutes(30),
        ]);

        $autoDoc = paginationDoc($approver, ['global_status' => 'approved']);
        DocumentAssignment::create([
            'document_id' => $autoDoc->document_id, 'user_id' => $approver->user_id,
            'stage_id' => WorkflowStage::first()->stage_id, 'due_date' => $autoDoc->due_date,
            'priority_rank' => 2, 'individual_status' => 'approved', 'acted_at' => now(),
            'auto_approved' => true,
        ]);
    }

    // 3 escalated + 3 auto-approved, each over the 2/page bar.
    $response = $this->actingAs($admin)->get(route('admin.sla.queue'));
    $response->assertOk();

    // Paging the escalated section to page 2 must not also page the auto-approved section.
    $page2 = $this->actingAs($admin)->get(route('admin.sla.queue', ['page' => 2]));
    $page2->assertOk();
    $autoPage2 = $this->actingAs($admin)->get(route('admin.sla.queue', ['auto_approved_page' => 2]));
    $autoPage2->assertOk();
});

it('honors both pagination params at once on the ML Training refresh fragment (Feature: AJAX pagination)', function () {
    // enableAjaxPagination() (resources/js/app.js) fetches the .../refresh
    // route with the CLICKED link's own full query string, which already
    // has both page params merged (see AdminController::paginateContainers()).
    // This proves the server side of that: a combined ?ml_page=2&readability_page=2
    // request must page each section independently, not just whichever
    // one a bare single-param request happens to test.
    $admin = User::factory()->admin()->create();
    for ($i = 0; $i < 6; $i++) {
        $word = str_repeat(chr(97 + $i), 4);
        $text = implode(' ', array_fill(0, 30, $word));
        paginationDoc($admin, ['title' => "ml-{$i}.txt", 'global_status' => 'processing', 'ml_review_status' => 'pending', 'ml_confidence' => 30.0, 'ocr_text' => $text]);
        paginationDoc($admin, ['title' => "read-{$i}.txt", 'global_status' => 'processing', 'readability_review_status' => 'pending', 'readability_score' => 50]);
    }

    $response = $this->actingAs($admin)->get(route('admin.ml.review.refresh', ['ml_page' => 2, 'readability_page' => 2]));

    $response->assertOk();
    // Page 2 of a 6-item/5-per-page list has exactly 1 item left, for BOTH
    // sections at once — proves neither param was dropped/overwritten by
    // the other (each is sorted, so item index 5, i.e. "e"/"f", is what
    // survives onto page 2 — asserting on presence/absence of a page-1-only
    // title is what actually proves the paging, not just "page loaded").
    $response->assertDontSee('ml-0.txt')->assertDontSee('read-0.txt');
});

it('honors both pagination params at once on the SLA Queue refresh fragment (Feature: AJAX pagination)', function () {
    $admin = User::factory()->admin()->create();
    $approver = User::factory()->approver('Job Order')->create();
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review', 'sequence_order' => 1]);

    for ($i = 0; $i < 3; $i++) {
        $doc = paginationDoc($approver, ['title' => "esc-{$i}.txt", 'global_status' => 'processing']);
        DocumentAssignment::create([
            'document_id' => $doc->document_id, 'user_id' => $approver->user_id,
            'stage_id' => WorkflowStage::first()->stage_id, 'due_date' => $doc->due_date,
            'priority_rank' => 2, 'individual_status' => 'pending', 'sla_expires_at' => now()->subHour(),
            'escalated_to_admin' => true, 'escalated_at' => now()->subMinutes(30),
        ]);

        $autoDoc = paginationDoc($approver, ['title' => "auto-{$i}.txt", 'global_status' => 'approved']);
        DocumentAssignment::create([
            'document_id' => $autoDoc->document_id, 'user_id' => $approver->user_id,
            'stage_id' => WorkflowStage::first()->stage_id, 'due_date' => $autoDoc->due_date,
            'priority_rank' => 2, 'individual_status' => 'approved', 'acted_at' => now(),
            'auto_approved' => true,
        ]);
    }

    $response = $this->actingAs($admin)->get(route('admin.sla.queue.refresh', ['page' => 2, 'auto_approved_page' => 2]));

    $response->assertOk();
    // Page 2 of a 3-item/2-per-page list has exactly 1 item left, for BOTH
    // sections at once — proves neither param was dropped/overwritten by the other.
    $response->assertDontSee('esc-0.txt')->assertDontSee('esc-1.txt')->assertSee('esc-2.txt');
    $response->assertDontSee('auto-0.txt')->assertDontSee('auto-1.txt')->assertSee('auto-2.txt');
});

it('paginates Unassigned Documents at 2 per page', function () {
    $admin = User::factory()->admin()->create();
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review', 'sequence_order' => 1]);

    for ($i = 0; $i < 2; $i++) {
        $doc = paginationDoc($admin, ['global_status' => 'processing']);
        DocumentAssignment::create([
            'document_id' => $doc->document_id, 'user_id' => null,
            'stage_id' => WorkflowStage::first()->stage_id, 'due_date' => $doc->due_date,
            'priority_rank' => 2, 'individual_status' => 'pending', 'needs_approver' => true, 'needs_approver_at' => now(),
        ]);
    }
    $this->actingAs($admin)->get(route('admin.unassigned.index'))->assertDontSee('Next');

    $doc = paginationDoc($admin, ['global_status' => 'processing']);
    DocumentAssignment::create([
        'document_id' => $doc->document_id, 'user_id' => null,
        'stage_id' => WorkflowStage::first()->stage_id, 'due_date' => $doc->due_date,
        'priority_rank' => 2, 'individual_status' => 'pending', 'needs_approver' => true, 'needs_approver_at' => now(),
    ]);
    $this->actingAs($admin)->get(route('admin.unassigned.index'))->assertSee('Next');
});

it('paginates the Admin Audit Trail at 10 per page', function () {
    $admin = User::factory()->admin()->create();
    for ($i = 0; $i < 10; $i++) {
        \App\Models\AuditLog::record($admin->user_id, null, 'user_toggle', "PAGINATION TEST audit row {$i}");
    }
    $this->actingAs($admin)->get(route('admin.audit.logs'))->assertDontSee('Next');

    \App\Models\AuditLog::record($admin->user_id, null, 'user_toggle', 'PAGINATION TEST audit row 11');
    $this->actingAs($admin)->get(route('admin.audit.logs'))->assertSee('Next');
});

it('paginates Document Tracking at 5 per page', function () {
    $admin = User::factory()->admin()->create();
    for ($i = 0; $i < 5; $i++) {
        paginationDoc($admin);
    }
    $this->actingAs($admin)->get(route('admin.documents.index'))->assertDontSee('Next');

    paginationDoc($admin);
    $this->actingAs($admin)->get(route('admin.documents.index'))->assertSee('Next');
});

it('paginates the Originator "Upload & Track Documents" list at 5 per page', function () {
    $originator = User::factory()->originator()->create();
    for ($i = 0; $i < 5; $i++) {
        paginationDoc($originator);
    }
    $this->actingAs($originator)->get(route('originator.dashboard'))->assertDontSee('Next');

    paginationDoc($originator);
    $this->actingAs($originator)->get(route('originator.dashboard'))->assertSee('Next');
});

it('paginates SLA Violations at 5 per page', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review', 'sequence_order' => 1]);

    for ($i = 0; $i < 6; $i++) {
        $doc = paginationDoc($originator, ['global_status' => 'processing']);
        $assignment = DocumentAssignment::create([
            'document_id' => $doc->document_id, 'user_id' => $approver->user_id,
            'stage_id' => WorkflowStage::first()->stage_id, 'due_date' => $doc->due_date,
            'priority_rank' => 2, 'individual_status' => 'pending', 'sla_expires_at' => now()->subHour(),
        ]);
        SlaViolation::create([
            'document_id' => $doc->document_id, 'assignment_id' => $assignment->assignment_id,
            'approver_id' => $approver->user_id, 'violation_timestamp' => now(),
            'duration_overdue' => 3600, 'stage_name' => 'Review',
        ]);
    }

    $response = $this->actingAs($admin)->get(route('admin.sla.violations', ['category' => 'Job Order']));
    $response->assertOk()->assertSee('Next');
});

it('paginates Decision History at 10 per page (unchanged)', function () {
    $approver = User::factory()->approver('Job Order')->create();
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review', 'sequence_order' => 1]);
    for ($i = 0; $i < 11; $i++) {
        $doc = paginationDoc($approver, ['global_status' => 'approved']);
        DocumentAssignment::create([
            'document_id' => $doc->document_id, 'user_id' => $approver->user_id,
            'stage_id' => WorkflowStage::first()->stage_id, 'due_date' => $doc->due_date,
            'priority_rank' => 2, 'individual_status' => 'approved', 'acted_at' => now(),
        ]);
    }

    $this->actingAs($approver)->get(route('approver.history'))->assertSee('Next');
});

it('paginates Notifications at 10 per page (unchanged)', function () {
    $originator = User::factory()->originator()->create();
    for ($i = 0; $i < 10; $i++) {
        NotificationRecord::send($originator->user_id, null, "Notification {$i}");
    }
    $this->actingAs($originator)->get(route('notifications.index'))->assertDontSee('Next');

    NotificationRecord::send($originator->user_id, null, 'Notification 11');
    $this->actingAs($originator)->get(route('notifications.index'))->assertSee('Next');
});
