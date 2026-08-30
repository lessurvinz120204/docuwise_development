<?php

use App\Models\AuditLog;
use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\User;
use App\Models\WorkflowStage;

beforeEach(function () {
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Budget Check', 'sequence_order' => 1]);
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Final Approval', 'sequence_order' => 2]);
    WorkflowStage::create(['document_category' => 'Purchase Requisition', 'stage_name' => 'Procurement Review', 'sequence_order' => 1]);
});

function pendingAssignmentFor(User $approver, WorkflowStage $stage): DocumentAssignment
{
    $originator = User::factory()->originator()->create();
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'doc.txt',
        'file_path' => 'documents/doc.txt',
        'mime_type' => 'text/plain',
        'ml_category' => $stage->document_category,
        'is_validated' => true,
        'due_date' => now()->addHours(2),
        'global_status' => 'classified_validated',
    ]);

    return DocumentAssignment::create([
        'document_id' => $document->document_id,
        'stage_id' => $stage->stage_id,
        'user_id' => $approver->user_id,
        'individual_status' => 'pending',
        'sla_expires_at' => now()->addHour(),
        'priority_rank' => 1,
        'escalated_to_admin' => false,
        'auto_approved' => false,
    ]);
}

it('lets an admin reassign an approver to a different category, resetting stage picks', function () {
    $admin = User::factory()->admin()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $budgetCheck = WorkflowStage::where('stage_name', 'Budget Check')->first();
    $approver->workflowStages()->sync([$budgetCheck->stage_id]);

    $this->actingAs($admin)->post(route('admin.users.stages.update', $approver), [
        'assigned_category' => 'Purchase Requisition',
        'department' => 'Finance',
        'level' => 'staff',
        'stage_ids' => [],
    ])->assertRedirect(route('admin.users'));

    $approver->refresh();
    expect($approver->assigned_category)->toBe('Purchase Requisition');
    expect($approver->workflowStages()->count())->toBe(0);
});

it('does not touch an already-pending assignment when the approver is reassigned to a new category', function () {
    $admin = User::factory()->admin()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $budgetCheck = WorkflowStage::where('stage_name', 'Budget Check')->first();
    $assignment = pendingAssignmentFor($approver, $budgetCheck);

    $this->actingAs($admin)->post(route('admin.users.stages.update', $approver), [
        'assigned_category' => 'Purchase Requisition',
        'department' => 'Finance',
        'level' => 'staff',
        'stage_ids' => [],
    ]);

    $assignment->refresh();
    expect($assignment->user_id)->toBe($approver->user_id);
    expect($assignment->individual_status)->toBe('pending');
    expect($assignment->stage_id)->toBe($budgetCheck->stage_id);
});

it('still lets the approver decide on their pre-existing assignment after being moved to a new category', function () {
    $approver = User::factory()->approver('Job Order')->create();
    $budgetCheck = WorkflowStage::where('stage_name', 'Budget Check')->first();
    $assignment = pendingAssignmentFor($approver, $budgetCheck);

    $approver->assigned_category = 'Purchase Requisition';
    $approver->save();
    $approver->workflowStages()->sync([]);

    seedReviewTime($approver, $assignment->document);
    $response = $this->actingAs($approver)->post(route('approver.assignments.decide', $assignment), [
        'decision' => 'approved',
    ]);

    $response->assertRedirect(route('approver.dashboard'));
    expect($assignment->fresh()->individual_status)->toBe('approved');
});

it('rejects stage_ids that belong to a different category than the one submitted', function () {
    $admin = User::factory()->admin()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $procurementReview = WorkflowStage::where('stage_name', 'Procurement Review')->first();

    // Tampered request: category says Job Order, but the stage_id belongs to Purchase Requisition.
    $this->actingAs($admin)->post(route('admin.users.stages.update', $approver), [
        'assigned_category' => 'Job Order',
        'department' => 'Engineering',
        'level' => 'staff',
        'stage_ids' => [$procurementReview->stage_id],
    ]);

    expect($approver->workflowStages()->pluck('workflow_stages.stage_id'))->not->toContain($procurementReview->stage_id);
});

it('logs the category reassignment distinctly in the audit trail', function () {
    $admin = User::factory()->admin()->create();
    $approver = User::factory()->approver('Job Order')->create();

    $this->actingAs($admin)->post(route('admin.users.stages.update', $approver), [
        'assigned_category' => 'Purchase Requisition',
        'department' => 'Finance',
        'level' => 'staff',
        'stage_ids' => [],
    ]);

    $log = AuditLog::where('action_type', 'assign_stages')->latest('timestamp')->firstOrFail();
    expect($log->description)->toContain("from 'Job Order'")->toContain("to 'Purchase Requisition'/'Finance'");
});
