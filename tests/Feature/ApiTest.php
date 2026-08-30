<?php

use App\Models\AuditLog;
use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\User;
use App\Models\WorkflowStage;
use Illuminate\Http\UploadedFile;

beforeEach(function () {
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1]);
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Final Approval', 'sequence_order' => 2]);
});

it('rejects login with the wrong password', function () {
    User::factory()->originator()->create(['username' => 'jsantos', 'password_hash' => bcrypt('correct-password')]);

    $response = $this->postJson('/api/v1/login', [
        'username' => 'jsantos',
        'password' => 'wrong-password',
        'device_name' => 'test-device',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('username');
});

it('issues a bearer token on valid login and accepts it on a protected route', function () {
    $user = User::factory()->originator()->create(['username' => 'jsantos', 'password_hash' => bcrypt('correct-password')]);

    $login = $this->postJson('/api/v1/login', [
        'username' => 'jsantos',
        'password' => 'correct-password',
        'device_name' => 'test-device',
    ]);

    $login->assertOk()->assertJsonStructure(['token', 'user' => ['id', 'username', 'role']]);
    $token = $login->json('token');

    $me = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/me');
    $me->assertOk()->assertJson(['data' => ['username' => 'jsantos', 'id' => $user->user_id]]);
});

it('rejects requests to protected routes with no token', function () {
    $this->getJson('/api/v1/me')->assertStatus(401);
});

it('lets an originator submit a document via the API using the exact same classification/routing pipeline', function () {
    $originator = User::factory()->originator()->create();

    $this->travelTo(\Carbon\Carbon::parse('2026-08-12 10:00:00')); // Wednesday, business hours

    $response = $this->actingAs($originator, 'sanctum')->postJson('/api/v1/documents', [
        'files' => [UploadedFile::fake()->createWithContent('doc.txt', 'some document content ' . str_repeat('word ', 20))],
        'due_date' => now()->addHours(4)->format('Y-m-d\TH:i'),
    ]);

    $response->assertStatus(201);
    expect(DocumentRepository::count())->toBe(1);
});

it('blocks an approver from submitting a document via the API', function () {
    $approver = User::factory()->approver('Job Order')->create();

    $this->actingAs($approver, 'sanctum')->postJson('/api/v1/documents', [
        'files' => [UploadedFile::fake()->createWithContent('doc.txt', 'content')],
        'due_date' => now()->addHours(2)->format('Y-m-d\TH:i'),
    ])->assertStatus(403);
});

it('only shows an originator their own documents via the API', function () {
    $mine = User::factory()->originator()->create();
    $someoneElse = User::factory()->originator()->create();

    DocumentRepository::create([
        'originator_id' => $mine->user_id, 'title' => 'mine.txt', 'file_path' => 'documents/mine.txt',
        'mime_type' => 'text/plain', 'ml_category' => 'Job Order', 'is_validated' => true,
        'due_date' => now()->addHours(2), 'global_status' => 'classified_validated',
    ]);
    DocumentRepository::create([
        'originator_id' => $someoneElse->user_id, 'title' => 'not-mine.txt', 'file_path' => 'documents/not-mine.txt',
        'mime_type' => 'text/plain', 'ml_category' => 'Job Order', 'is_validated' => true,
        'due_date' => now()->addHours(2), 'global_status' => 'classified_validated',
    ]);

    $response = $this->actingAs($mine, 'sanctum')->getJson('/api/v1/documents');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.title'))->toBe('mine.txt');
});

it('lets an approver decide their own pending assignment via the API', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $stage = WorkflowStage::where('stage_name', 'Technical Review')->first();

    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'doc.txt', 'file_path' => 'documents/doc.txt',
        'mime_type' => 'text/plain', 'ml_category' => 'Job Order', 'is_validated' => true,
        'due_date' => now()->addHours(2), 'global_status' => 'classified_validated',
    ]);
    $assignment = DocumentAssignment::create([
        'document_id' => $document->document_id, 'stage_id' => $stage->stage_id, 'user_id' => $approver->user_id,
        'individual_status' => 'pending', 'sla_expires_at' => now()->addHour(), 'priority_rank' => 1,
        'escalated_to_admin' => false, 'auto_approved' => false,
    ]);

    $response = $this->actingAs($approver, 'sanctum')->postJson("/api/v1/assignments/{$assignment->assignment_id}/decide", [
        'decision' => 'approved',
    ]);

    $response->assertOk()->assertJson(['data' => ['status' => 'approved']]);
});

it("blocks an approver from deciding on someone else's assignment via the API", function () {
    $originator = User::factory()->originator()->create();
    $approverA = User::factory()->approver('Job Order')->create();
    $approverB = User::factory()->approver('Job Order')->create();
    $stage = WorkflowStage::where('stage_name', 'Technical Review')->first();

    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'doc.txt', 'file_path' => 'documents/doc.txt',
        'mime_type' => 'text/plain', 'ml_category' => 'Job Order', 'is_validated' => true,
        'due_date' => now()->addHours(2), 'global_status' => 'classified_validated',
    ]);
    $assignment = DocumentAssignment::create([
        'document_id' => $document->document_id, 'stage_id' => $stage->stage_id, 'user_id' => $approverA->user_id,
        'individual_status' => 'pending', 'sla_expires_at' => now()->addHour(), 'priority_rank' => 1,
        'escalated_to_admin' => false, 'auto_approved' => false,
    ]);

    $this->actingAs($approverB, 'sanctum')->postJson("/api/v1/assignments/{$assignment->assignment_id}/decide", [
        'decision' => 'approved',
    ])->assertStatus(403);
});

it('revokes the token on logout, so it cannot be reused', function () {
    $user = User::factory()->originator()->create(['username' => 'jsantos', 'password_hash' => bcrypt('correct-password')]);
    $token = $user->createToken('test-device')->plainTextToken;
    $tokenId = explode('|', $token, 2)[0];

    $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/logout')->assertOk();

    // Asserted at the DB level rather than chaining a second HTTP call:
    // Laravel's auth guard caches the resolved user for the lifetime of
    // the test's shared app container, so a second simulated request in
    // the SAME test would incorrectly reuse that cached resolution
    // instead of re-checking the (now-deleted) token — an artifact of
    // the test harness, not a real request/response cycle. The row being
    // gone is the actual guarantee that matters.
    expect(\Laravel\Sanctum\PersonalAccessToken::find($tokenId))->toBeNull();
});

it('logs api_login to the audit trail', function () {
    User::factory()->originator()->create(['username' => 'jsantos', 'password_hash' => bcrypt('correct-password')]);

    $this->postJson('/api/v1/login', [
        'username' => 'jsantos', 'password' => 'correct-password', 'device_name' => 'test-device',
    ])->assertOk();

    $log = AuditLog::where('action_type', 'api_login')->latest('timestamp')->first();
    expect($log)->not->toBeNull();
    expect($log->description)->toContain('test-device');
});

it('logs api_logout to the audit trail', function () {
    $user = User::factory()->originator()->create();
    $token = $user->createToken('test-device')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/logout')->assertOk();

    $log = AuditLog::where('action_type', 'api_logout')->latest('timestamp')->first();
    expect($log)->not->toBeNull();
    expect($log->description)->toContain('test-device');
});

it('lets an originator view their own document via the API', function () {
    $originator = User::factory()->originator()->create();

    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'mine.txt', 'file_path' => 'documents/mine.txt',
        'mime_type' => 'text/plain', 'ml_category' => 'Job Order', 'is_validated' => true,
        'due_date' => now()->addHours(2), 'global_status' => 'classified_validated',
    ]);

    $response = $this->actingAs($originator, 'sanctum')->getJson("/api/v1/documents/{$document->document_id}");

    $response->assertOk()->assertJson(['data' => ['title' => 'mine.txt']]);
});

it("blocks an originator from viewing someone else's document via the API", function () {
    $owner = User::factory()->originator()->create();
    $someoneElse = User::factory()->originator()->create();

    $document = DocumentRepository::create([
        'originator_id' => $owner->user_id, 'title' => 'not-yours.txt', 'file_path' => 'documents/not-yours.txt',
        'mime_type' => 'text/plain', 'ml_category' => 'Job Order', 'is_validated' => true,
        'due_date' => now()->addHours(2), 'global_status' => 'classified_validated',
    ]);

    $this->actingAs($someoneElse, 'sanctum')
        ->getJson("/api/v1/documents/{$document->document_id}")
        ->assertStatus(403);
});

it('lets an admin view any document via the API', function () {
    $originator = User::factory()->originator()->create();
    $admin = User::factory()->admin()->create();

    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'someones.txt', 'file_path' => 'documents/someones.txt',
        'mime_type' => 'text/plain', 'ml_category' => 'Job Order', 'is_validated' => true,
        'due_date' => now()->addHours(2), 'global_status' => 'classified_validated',
    ]);

    $this->actingAs($admin, 'sanctum')
        ->getJson("/api/v1/documents/{$document->document_id}")
        ->assertOk();
});

it("only lists an approver's own assignments via the API", function () {
    $originator = User::factory()->originator()->create();
    $mine = User::factory()->approver('Job Order')->create();
    $someoneElse = User::factory()->approver('Job Order')->create();
    $stage = WorkflowStage::where('stage_name', 'Technical Review')->first();

    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'doc.txt', 'file_path' => 'documents/doc.txt',
        'mime_type' => 'text/plain', 'ml_category' => 'Job Order', 'is_validated' => true,
        'due_date' => now()->addHours(2), 'global_status' => 'classified_validated',
    ]);
    $myAssignment = DocumentAssignment::create([
        'document_id' => $document->document_id, 'stage_id' => $stage->stage_id, 'user_id' => $mine->user_id,
        'individual_status' => 'pending', 'sla_expires_at' => now()->addHour(), 'priority_rank' => 1,
        'escalated_to_admin' => false, 'auto_approved' => false,
    ]);
    DocumentAssignment::create([
        'document_id' => $document->document_id, 'stage_id' => $stage->stage_id, 'user_id' => $someoneElse->user_id,
        'individual_status' => 'pending', 'sla_expires_at' => now()->addHour(), 'priority_rank' => 1,
        'escalated_to_admin' => false, 'auto_approved' => false,
    ]);

    $response = $this->actingAs($mine, 'sanctum')->getJson('/api/v1/assignments');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.id'))->toBe($myAssignment->assignment_id);
});
