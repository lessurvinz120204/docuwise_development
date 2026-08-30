<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WORKFLOW_STAGE_DEPARTMENTS — which department(s) own a given workflow
 * stage. Most stages belong to exactly one department (Technical Review ->
 * Engineering only); a stage can belong to more than one where multiple
 * departments jointly hold Final Approval together (Job Order's Final
 * Approval -> Engineering AND Finance both), which is why this is a real
 * one-to-many table keyed on stage_id rather than a single department
 * column on workflow_stages.
 *
 * A stage with ZERO rows here is treated as unrestricted by department
 * (same backward-compatible "no restriction recorded yet" convention
 * approver_workflow_stages already uses for stage-level restriction) —
 * see WorkflowService::eligibleApproversForStage().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_stage_departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stage_id')->constrained('workflow_stages', 'stage_id')->cascadeOnDelete();
            $table->string('department');
            $table->timestamps();

            $table->unique(['stage_id', 'department']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_stage_departments');
    }
};
