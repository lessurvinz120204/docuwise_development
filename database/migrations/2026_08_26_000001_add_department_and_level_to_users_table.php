<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DEPARTMENT / LEVEL — an approver's department (Engineering, Finance, ...)
 * is a separate concept from assigned_category: a document CATEGORY (e.g.
 * "Job Order") can pass through multiple DEPARTMENTS across its stages
 * (Technical Review = Engineering, Budget Check = Finance), so an approver
 * needs their own department fixed independently of which category they
 * work in — see WorkflowStageDepartment for which department(s) each
 * individual stage belongs to.
 *
 * level distinguishes ordinary staff-level reviewers from department heads
 * — a head is the one who sits on a category's Final Approval stage, a
 * genuinely different reviewer from whoever did the earlier functional
 * stage in the same department (see the seeded accounts in DatabaseSeeder).
 *
 * Both nullable — only meaningful for role='approver'; admin/originator
 * accounts leave both null, same convention as assigned_category already
 * being null for non-approvers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('department')->nullable()->after('assigned_category');
            $table->string('level')->nullable()->after('department');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['department', 'level']);
        });
    }
};
