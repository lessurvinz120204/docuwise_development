<?php

use App\Http\Controllers\Api\AssignmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DocumentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (v1)
|--------------------------------------------------------------------------
| Token-based (Sanctum) equivalent of the core web flows in routes/web.php
| — login, document submission/tracking, and approver decisions — for
| mobile apps or external system integrations that can't drive the
| session-cookie + CSRF web UI. Every controller here is a thin wrapper
| around the exact same services (WorkflowService, SlaService) the web
| controllers use, so business logic (classification, SLA calculation,
| routing) lives in exactly one place regardless of which surface called it.
|
| RBAC is enforced the same way as the web app: per-request ownership/role
| checks inside each controller method, not just route-level gating —
| see each controller for the specifics (originator owns their documents,
| approver owns their assignments, admin sees everything).
*/

Route::prefix('v1')->name('api.')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:auth')->name('login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('/me', [AuthController::class, 'me'])->name('me');

        Route::get('/documents', [DocumentController::class, 'index'])->name('documents.index');
        Route::get('/documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
        Route::post('/documents', [DocumentController::class, 'store'])->middleware('throttle:mutations')->name('documents.store');

        Route::get('/assignments', [AssignmentController::class, 'index'])->name('assignments.index');
        Route::post('/assignments/{assignment}/decide', [AssignmentController::class, 'decide'])->middleware('throttle:mutations')->name('assignments.decide');
    });
});
