@extends('layouts.app')
@section('title', 'User Accounts')
@section('page-title', 'User Accounts')

@section('content')
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <div class="lg:col-span-1">
        <div class="bg-white rounded-xl shadow-card border border-surface-200 p-6">
            <h2 class="text-sm font-semibold text-surface-900 mb-4">Create Account</h2>
            <form method="POST" action="{{ route('admin.users.store') }}" class="space-y-3">
                @csrf
                <div>
                    <label class="block text-xs font-medium text-surface-700 mb-1">Full Name</label>
                    <input name="full_name" required value="{{ old('full_name') }}" class="w-full rounded-lg border-surface-300 text-sm px-3 py-2 focus:border-primary-500 focus:ring-primary-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-surface-700 mb-1">Username</label>
                    <input name="username" required value="{{ old('username') }}" class="w-full rounded-lg border-surface-300 text-sm px-3 py-2 focus:border-primary-500 focus:ring-primary-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-surface-700 mb-1">Email</label>
                    <input type="email" name="email" required value="{{ old('email') }}" class="w-full rounded-lg border-surface-300 text-sm px-3 py-2 focus:border-primary-500 focus:ring-primary-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-surface-700 mb-1">Role</label>
                    <select name="role" id="create-role" required class="w-full rounded-lg border-surface-300 text-sm px-3 py-2 focus:border-primary-500 focus:ring-primary-500">
                        <option value="originator" @selected(old('role', 'originator') === 'originator')>Staff (Originator)</option>
                        <option value="approver" @selected(old('role') === 'approver')>Staff (Approver)</option>
                        <option value="admin" @selected(old('role') === 'admin')>Admin</option>
                    </select>
                </div>
                <div id="create-category-field">
                    <label class="block text-xs font-medium text-surface-700 mb-1">
                        Assigned Category
                        <span class="text-surface-400 font-normal">(Approvers only — changeable later via "Manage Category & Stages")</span>
                    </label>
                    <select name="assigned_category" id="create-category" class="w-full rounded-lg border-surface-300 text-sm px-3 py-2 focus:border-primary-500 focus:ring-primary-500">
                        @foreach(\App\Services\ValidationService::knownCategories() as $c)
                            <option value="{{ $c }}" @selected(old('assigned_category') === $c)>{{ $c }}</option>
                        @endforeach
                    </select>
                </div>

                <div id="create-department-field">
                    <label class="block text-xs font-medium text-surface-700 mb-1">
                        Department
                        <span class="text-surface-400 font-normal">(determines which stages below can be picked)</span>
                    </label>
                    <select name="department" id="create-department" class="w-full rounded-lg border-surface-300 text-sm px-3 py-2 focus:border-primary-500 focus:ring-primary-500">
                        @foreach(\App\Models\User::knownDepartments() as $d)
                            <option value="{{ $d }}" @selected(old('department') === $d)>{{ $d }}</option>
                        @endforeach
                    </select>
                </div>

                <div id="create-level-field">
                    <label class="block text-xs font-medium text-surface-700 mb-1">Level</label>
                    <select name="level" id="create-level" class="w-full rounded-lg border-surface-300 text-sm px-3 py-2 focus:border-primary-500 focus:ring-primary-500">
                        @foreach(\App\Models\User::knownLevels() as $l)
                            <option value="{{ $l }}" @selected(old('level') === $l)>{{ ucfirst($l) }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Stage picker: one checkbox-group per category, JS shows only the group matching the selected
                     category, and within it, only the stages the selected department actually owns (a stage with
                     no department tags at all is shown regardless — unrestricted). --}}
                <div id="create-stages-field">
                    <label class="block text-xs font-medium text-surface-700 mb-1">
                        Specific Stages <span class="text-surface-400 font-normal">(optional — leave all unchecked for every stage this department owns in this category)</span>
                    </label>
                    @foreach($stagesByCategory as $category => $categoryStages)
                        <div class="stage-group space-y-1 {{ !$loop->first ? 'hidden' : '' }}" data-category="{{ $category }}">
                            @forelse($categoryStages as $stage)
                                <label class="stage-option flex items-center gap-2 text-xs text-surface-600" data-departments="{{ implode(',', $stage->departmentNames()) }}">
                                    <input type="checkbox" name="stage_ids[]" value="{{ $stage->stage_id }}" @checked(in_array($stage->stage_id, old('stage_ids', []))) class="rounded border-surface-300 text-primary-700 focus:ring-primary-500">
                                    {{ $stage->sequence_order }}. {{ $stage->stage_name }}
                                    @if($stage->departmentNames())
                                        <span class="text-surface-400">({{ implode(' + ', $stage->departmentNames()) }})</span>
                                    @endif
                                </label>
                            @empty
                                <p class="text-xs text-surface-400">No stages configured for {{ $category }} yet.</p>
                            @endforelse
                        </div>
                    @endforeach
                </div>

                <div>
                    <label for="create-password" class="block text-xs font-medium text-surface-700 mb-1">Password</label>
                    <x-password-input id="create-password" name="password" required minlength="8" class="!py-2" />
                    <x-password-requirements for="create-password" />
                </div>
                <button class="w-full bg-primary-700 hover:bg-primary-800 text-white text-sm font-medium py-2.5 rounded-lg transition-colors">Create Account</button>
            </form>
        </div>
    </div>

    <div class="lg:col-span-2" id="users-table"
        data-refresh-url="{{ route('admin.users.refresh') }}"
        data-poll-url="{{ route('admin.users.poll') }}">
        @include('admin.partials.users_table')
    </div>
</div>

<script>
    // Same live-update pattern used elsewhere (see admin/dashboard.blade.php,
    // ml_training.blade.php) — pushes the "Unverified" badge/Resend action
    // away the instant an admin (or anyone else watching this page) sees
    // someone actually click their verification link, not on next reload.
    document.addEventListener('DOMContentLoaded', function () {
        const tableEl = document.getElementById('users-table');
        if (!tableEl) return;

        const opts = {
            refreshUrl: tableEl.dataset.refreshUrl,
            target: tableEl,
            preserveQueryString: true, // keep ?show_inactive=1 across a live swap
        };

        startLiveChannel('admin-dashboard', '.user.verified', opts);
        startLivePoll({ ...opts, pollUrl: tableEl.dataset.pollUrl });
    });
</script>
@endsection

@push('scripts')
<script>
    const roleSelect = document.getElementById('create-role');
    const categoryField = document.getElementById('create-category-field');
    const departmentField = document.getElementById('create-department-field');
    const levelField = document.getElementById('create-level-field');
    const stagesField = document.getElementById('create-stages-field');
    const categorySelect = document.getElementById('create-category');
    const departmentSelect = document.getElementById('create-department');
    const stageGroups = stagesField.querySelectorAll('.stage-group');

    const toggleApproverFields = () => {
        const show = roleSelect.value === 'approver';
        categoryField.style.display = show ? 'block' : 'none';
        departmentField.style.display = show ? 'block' : 'none';
        levelField.style.display = show ? 'block' : 'none';
        stagesField.style.display = show ? 'block' : 'none';
    };

    // Shows only the stage-group matching the selected category, and within
    // it, only stages the selected department actually owns (a stage with
    // no data-departments at all — unrestricted — always shows). Unchecking
    // a hidden option keeps a stale, invisible pick from silently riding
    // along if the admin flips category/department back and forth.
    const showStagesForCategoryAndDepartment = () => {
        stageGroups.forEach(group => {
            const categoryMatches = group.dataset.category === categorySelect.value;
            group.classList.toggle('hidden', !categoryMatches);

            group.querySelectorAll('.stage-option').forEach(option => {
                const owners = option.dataset.departments ? option.dataset.departments.split(',') : [];
                const departmentMatches = owners.length === 0 || owners.includes(departmentSelect.value);
                const visible = categoryMatches && departmentMatches;
                option.classList.toggle('hidden', !visible);
                if (!visible) {
                    option.querySelector('input[type=checkbox]').checked = false;
                }
            });
        });
    };

    roleSelect.addEventListener('change', toggleApproverFields);
    categorySelect.addEventListener('change', showStagesForCategoryAndDepartment);
    departmentSelect.addEventListener('change', showStagesForCategoryAndDepartment);
    toggleApproverFields();
    showStagesForCategoryAndDepartment();
</script>
@endpush