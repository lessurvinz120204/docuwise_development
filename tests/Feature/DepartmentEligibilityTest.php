<?php

use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Models\WorkflowStageDepartment;
use App\Services\WorkflowService;

/**
 * Regression coverage for department-based approver eligibility — an
 * approver's department must match a stage's own department ownership
 * (WorkflowStageDepartment), on top of the existing category+stage-
 * restriction check. Exists specifically because one account previously
 * held stage access spanning two different departments (Technical Review +
 * Budget Check on the same Job Order account) before this feature existed.
 */
function documentFor(string $category): DocumentRepository
{
    $originator = User::factory()->originator()->create();

    return DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'doc.txt',
        'file_path' => 'documents/doc.txt',
        'mime_type' => 'text/plain',
        'ml_category' => $category,
        'is_validated' => true,
        'due_date' => now()->addHours(4),
        'global_status' => 'classified_validated',
    ]);
}

beforeEach(function () {
    $this->techReview = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1]);
    $this->budgetCheck = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Budget Check', 'sequence_order' => 2]);
    $this->finalApproval = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Final Approval', 'sequence_order' => 3]);

    WorkflowStageDepartment::create(['stage_id' => $this->techReview->stage_id, 'department' => 'Engineering']);
    WorkflowStageDepartment::create(['stage_id' => $this->budgetCheck->stage_id, 'department' => 'Finance']);
    WorkflowStageDepartment::create(['stage_id' => $this->finalApproval->stage_id, 'department' => 'Engineering']);
    WorkflowStageDepartment::create(['stage_id' => $this->finalApproval->stage_id, 'department' => 'Finance']);
});

it('routes a stage only to approvers whose department matches, even when category and staff-level checks pass', function () {
    $engineer = User::factory()->approver('Job Order')->create(['department' => 'Engineering', 'level' => 'staff']);
    $financeStaff = User::factory()->approver('Job Order')->create(['department' => 'Finance', 'level' => 'staff']);

    $document = documentFor('Job Order');
    app(WorkflowService::class)->routeToWorkflow($document);

    // Technical Review (Engineering-owned) should only seat the engineer.
    $techReviewAssignees = DocumentAssignment::where('document_id', $document->document_id)
        ->where('stage_id', $this->techReview->stage_id)
        ->pluck('user_id');

    expect($techReviewAssignees->all())->toBe([$engineer->user_id]);

    // Budget Check (Finance-owned) should only seat the finance staffer.
    $budgetAssignees = DocumentAssignment::where('document_id', $document->document_id)
        ->where('stage_id', $this->budgetCheck->stage_id)
        ->pluck('user_id');

    expect($budgetAssignees->all())->toBe([$financeStaff->user_id]);
});

it('seats both departments on a stage owned by more than one department (Final Approval)', function () {
    $engineerHead = User::factory()->approver('Job Order')->create(['department' => 'Engineering', 'level' => 'head']);
    $financeHead = User::factory()->approver('Job Order')->create(['department' => 'Finance', 'level' => 'head']);

    $document = documentFor('Job Order');
    app(WorkflowService::class)->routeToWorkflow($document);

    $finalApprovers = DocumentAssignment::where('document_id', $document->document_id)
        ->where('stage_id', $this->finalApproval->stage_id)
        ->pluck('user_id')
        ->sort()
        ->values();

    expect($finalApprovers->all())->toBe(collect([$engineerHead->user_id, $financeHead->user_id])->sort()->values()->all());
});

it('does not restrict eligibility by department for a stage with no department rows at all (backward compatible)', function () {
    $untaggedStage = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Untagged Stage', 'sequence_order' => 4]);
    // Deliberately no WorkflowStageDepartment rows for $untaggedStage.

    $anyApprover = User::factory()->approver('Job Order')->create(['department' => 'Finance', 'level' => 'staff']);
    $anyApprover->workflowStages()->sync([$untaggedStage->stage_id]);

    $document = documentFor('Job Order');
    app(WorkflowService::class)->routeToWorkflow($document);

    $assignees = DocumentAssignment::where('document_id', $document->document_id)
        ->where('stage_id', $untaggedStage->stage_id)
        ->pluck('user_id');

    expect($assignees->all())->toBe([$anyApprover->user_id]);
});

it('drops a stage_id the submitted department does not own when an admin creates an approver account', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post(route('admin.users.store'), [
        'username' => 'newengineer',
        'full_name' => 'New Engineer',
        'email' => 'newengineer@example.com',
        'role' => 'approver',
        'assigned_category' => 'Job Order',
        'department' => 'Engineering',
        'level' => 'staff',
        // Tampered: Budget Check belongs to Finance, not Engineering.
        'stage_ids' => [$this->techReview->stage_id, $this->budgetCheck->stage_id],
        'password' => 'Qz8kVn4RTwmp',
    ]);

    $created = User::where('username', 'newengineer')->firstOrFail();
    $stageIds = $created->workflowStages()->pluck('workflow_stages.stage_id');

    expect($stageIds->all())->toBe([$this->techReview->stage_id]);
});

it('drops a stage_id the submitted department does not own when an admin updates an approver', function () {
    $admin = User::factory()->admin()->create();
    $approver = User::factory()->approver('Job Order')->create(['department' => 'Finance', 'level' => 'staff']);

    $this->actingAs($admin)->post(route('admin.users.stages.update', $approver), [
        'assigned_category' => 'Job Order',
        'department' => 'Finance',
        'level' => 'staff',
        // Tampered: Technical Review belongs to Engineering, not Finance.
        'stage_ids' => [$this->budgetCheck->stage_id, $this->techReview->stage_id],
    ]);

    $stageIds = $approver->workflowStages()->pluck('workflow_stages.stage_id');

    expect($stageIds->all())->toBe([$this->budgetCheck->stage_id]);
});
