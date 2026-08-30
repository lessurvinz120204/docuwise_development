<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\WorkflowStage;
use App\Models\WorkflowStageDepartment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Idempotent by design — updateOrCreate()/firstOrCreate() keyed on the
     * unique columns, not create(). Running `php artisan db:seed` a second
     * time (without a fresh migration first) previously threw a duplicate
     * `username` error; it should always be safe to re-run.
     *
     * Real accounts, real Gmail addresses — start UNVERIFIED (no
     * email_verified_at), same as any account an admin creates through the
     * UI, and each gets a real verification email sent below. Necessary,
     * not just convenient: on a genuinely fresh `migrate:fresh --seed`
     * (a new deploy, or wiping this dev database), NOBODY can log in until
     * verified, and there's no admin session yet to click "Resend
     * verification" for anyone — including themselves. Without sending
     * here, a fresh install would have no way in at all. See
     * AuthController::login()/verifyEmail() and User::
     * sendEmailVerificationNotification().
     */
    public function run(): void
    {
        $admin = User::updateOrCreate(
            ['username' => 'rvinz'],
            [
                'full_name' => 'Russel Vinz',
                'email' => 'aganarusselvinz@gmail.com',
                'role' => 'admin',
                'assigned_category' => null,
                'password_hash' => Hash::make('rvinz123'),
                'is_active' => true,
            ]
        );

        // Real, currently-active originator account that existed live but
        // was never actually represented in this seeder file — a fresh
        // `migrate:fresh --seed` install would silently never reproduce it.
        // Added to close that gap; not the same person/account as
        // $vinzLessurApprover below despite the superficially similar name
        // pattern (see that account's own docblock).
        $allenRose = User::updateOrCreate(
            ['username' => 'arose'],
            [
                'full_name' => 'Allen Rose',
                'email' => 'anastacioalena23@gmail.com',
                'role' => 'originator',
                'assigned_category' => null,
                'password_hash' => Hash::make('arose123'),
                'created_by' => $admin->user_id,
                'is_active' => true,
            ]
        );

        // Job Order — Engineering department, staff level: Technical Review
        // only. Previously unrestricted (eligible for every Job Order
        // stage); narrowed once department separation meant an Engineering
        // account could no longer legitimately also hold Budget Check
        // (Finance) or Final Approval (head-level, not staff) — see
        // WorkflowStageDepartment / the department-eligibility check in
        // WorkflowService::eligibleApproversForStage().
        $lessurVinz = User::updateOrCreate(
            ['username' => 'lvinz'],
            [
                'full_name' => 'Lessur Vinz',
                'email' => 'lessurvinz@gmail.com',
                'role' => 'approver',
                'assigned_category' => 'Job Order',
                'department' => 'Engineering',
                'level' => 'staff',
                'password_hash' => Hash::make('lvinz123'),
                'created_by' => $admin->user_id,
                'is_active' => true,
            ]
        );

        // Engineering department HEAD — sits on Job Order's Final Approval
        // alongside the Finance head below (Final Approval requires both,
        // unanimous, same as every other multi-seat stage). Was previously
        // an unrestricted staff-level account and inactive; reactivated and
        // promoted, not created fresh, at the user's specific request.
        $christian = User::updateOrCreate(
            ['username' => 'cperalta'],
            [
                'full_name' => 'Christian',
                'email' => 'peraltachristian.m@gmail.com',
                'role' => 'approver',
                'assigned_category' => 'Job Order',
                'department' => 'Engineering',
                'level' => 'head',
                'password_hash' => Hash::make('cperalta123'),
                'created_by' => $admin->user_id,
                'is_active' => true,
            ]
        );

        // Job Order — Finance department, staff level: Budget Check only.
        // Live username renamed from "Vinz Lessur" (with a space — it had
        // been created directly through the admin UI, not this seeder, so
        // it never followed this file's short-username convention) to
        // 'vlessur' to match this block, so this stays a real update rather
        // than accidentally inserting a second, duplicate account.
        // Previously held both Technical Review (Engineering) AND Budget
        // Check (Finance) on one account; narrowed to Budget Check only for
        // the same department-separation reason as $lessurVinz above.
        $vinzLessurApprover = User::updateOrCreate(
            ['username' => 'vlessur'],
            [
                'full_name' => 'Vinz Lessur',
                'email' => 'vinzlessur@gmail.com',
                'role' => 'approver',
                'assigned_category' => 'Job Order',
                'department' => 'Finance',
                'level' => 'staff',
                'password_hash' => Hash::make('vlessur123'),
                'created_by' => $admin->user_id,
                'is_active' => true,
            ]
        );

        // Finance department HEAD — the other required seat on Job Order's
        // Final Approval, alongside $christian above.
        $financeHead = User::updateOrCreate(
            ['username' => 'gfunelas'],
            [
                'full_name' => 'Gian Funelas',
                'email' => 'gianfunelas175@gmail.com',
                'role' => 'approver',
                'assigned_category' => 'Job Order',
                'department' => 'Finance',
                'level' => 'head',
                'password_hash' => Hash::make('gfunelas123'),
                'created_by' => $admin->user_id,
                'is_active' => true,
            ]
        );

        // Skips anyone already verified — a re-run of this idempotent
        // seeder (e.g. `db:seed` again without `migrate:fresh` first)
        // shouldn't re-send a link to an account that already clicked it.
        foreach ([$admin, $allenRose, $lessurVinz, $christian, $vinzLessurApprover, $financeHead] as $seededUser) {
            if (!$seededUser->hasVerifiedEmail()) {
                $seededUser->sendEmailVerificationNotification();
            }
        }

        // Default workflow pipelines per document category (Scope 1.4)
        $pipelines = [
            'Job Order' => ['Technical Review', 'Budget Check', 'Final Approval'],
            'Purchase Requisition' => ['Budget Check', 'Procurement Review', 'Final Approval'],
            'Service Report' => ['Quality Inspection', 'Final Approval'],
        ];

        $stagesByName = [];
        foreach ($pipelines as $category => $stages) {
            foreach ($stages as $i => $name) {
                $stage = WorkflowStage::firstOrCreate(
                    ['document_category' => $category, 'sequence_order' => $i + 1],
                    ['stage_name' => $name, 'description' => "{$name} for {$category} documents."]
                );
                $stagesByName["{$category}:{$name}"] = $stage;
            }
        }

        // Which department(s) own each stage — most stages belong to
        // exactly one department; Job Order's Final Approval belongs to
        // both (see WorkflowStageDepartment's docblock for why that's a
        // real one-to-many table rather than a single column). Purchase
        // Requisition and Service Report's Final Approval each stay
        // single-department for now (Finance and Engineering respectively)
        // since no head account has been set up yet to cover the other
        // department there — see the open question in this feature's plan.
        $stageDepartments = [
            'Job Order:Technical Review' => ['Engineering'],
            'Job Order:Budget Check' => ['Finance'],
            'Job Order:Final Approval' => ['Engineering', 'Finance'],
            'Purchase Requisition:Budget Check' => ['Finance'],
            'Purchase Requisition:Procurement Review' => ['Finance'],
            'Purchase Requisition:Final Approval' => ['Finance'],
            'Service Report:Quality Inspection' => ['Engineering'],
            'Service Report:Final Approval' => ['Engineering'],
        ];

        foreach ($stageDepartments as $key => $departments) {
            foreach ($departments as $department) {
                WorkflowStageDepartment::firstOrCreate([
                    'stage_id' => $stagesByName[$key]->stage_id,
                    'department' => $department,
                ]);
            }
        }

        // Restrict each seeded approver to exactly the stage their
        // department/level combination owns (see the account definitions
        // above) — sync() is idempotent, safe to re-run.
        $lessurVinz->workflowStages()->sync([$stagesByName['Job Order:Technical Review']->stage_id]);
        $vinzLessurApprover->workflowStages()->sync([$stagesByName['Job Order:Budget Check']->stage_id]);
        $christian->workflowStages()->sync([$stagesByName['Job Order:Final Approval']->stage_id]);
        $financeHead->workflowStages()->sync([$stagesByName['Job Order:Final Approval']->stage_id]);
    }
}
