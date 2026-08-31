{{--
    Sign In Backup Codes — two entry points into the same modal:
    1) the Admin Users table, password-gated behind the TARGET user's own
       current password (see AdminController::backupCodes()), for
       recovering someone who's lost access to their emailed 2FA code;
    2) a user's OWN first successful login (see AuthController::login()'s
       new_backup_codes flash + layouts/app.blade.php), which skips the
       password gate entirely and goes straight to the code list — no
       need to re-prove a password someone just typed into the login form
       seconds ago. One shared modal either way, invoked via JS from
       wherever it's needed — same fetch-and-render pattern
       kpi-drilldown-modal.blade.php already uses.
--}}
{{-- No click-outside-to-close — these codes are shown once; an accidental
     backdrop click must never be able to dismiss them before they're
     saved. The X button is the only way out. --}}
<div id="backup-codes-overlay" class="hidden fixed inset-0 z-50 bg-surface-900/60 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-sm overflow-hidden">
        <div class="flex items-center justify-between px-6 py-4 border-b border-surface-200">
            <h3 id="backup-codes-title" class="text-sm font-semibold text-surface-900"></h3>
            <button type="button" onclick="closeBackupCodesModal()" class="text-surface-400 hover:text-surface-700" aria-label="Close">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        {{-- View 1: password gate --}}
        <div id="backup-codes-password-view" class="p-6">
            <p class="text-xs text-surface-500 mb-3">
                Ask <span id="backup-codes-user-name-2"></span> for their current password before continuing — this confirms the request is really coming from them, not just anyone with Admin access.
            </p>
            <label class="block text-xs font-medium text-surface-700 mb-1">Their current password</label>
            <input type="password" id="backup-codes-password-input" autocomplete="off"
                class="w-full rounded-lg border-surface-300 text-sm px-3 py-2 focus:border-primary-500 focus:ring-primary-500">
            <p id="backup-codes-error" class="hidden mt-2 text-xs text-rejected-700"></p>
            <button type="button" id="backup-codes-verify-btn" onclick="submitBackupCodesPassword()"
                class="mt-4 w-full bg-primary-700 hover:bg-primary-800 text-white text-sm font-medium py-2 rounded-lg transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                Verify &amp; View Codes
            </button>
        </div>

        {{-- View 2: the codes themselves --}}
        <div id="backup-codes-list-view" class="hidden p-6">
            <p id="backup-codes-save-now-note" class="hidden mb-3 text-xs font-medium text-processing-700 bg-processing-50 border border-processing-500/25 rounded-lg px-3 py-2"></p>
            <ul id="backup-codes-list" class="space-y-2"></ul>
            <p class="mt-4 text-xs text-surface-400">Every code is single-use. Once all are used, a fresh set is generated automatically.</p>
        </div>
    </div>
</div>

<script>
    let backupCodesTargetUserId = null;

    function openBackupCodesModal(userId, userName) {
        backupCodesTargetUserId = userId;
        document.getElementById('backup-codes-title').textContent = 'Sign In Backup Codes — ' + userName;
        document.getElementById('backup-codes-user-name-2').textContent = userName;
        document.getElementById('backup-codes-password-input').value = '';
        document.getElementById('backup-codes-error').classList.add('hidden');
        document.getElementById('backup-codes-verify-btn').disabled = false;
        document.getElementById('backup-codes-save-now-note').classList.add('hidden');
        document.getElementById('backup-codes-password-view').classList.remove('hidden');
        document.getElementById('backup-codes-list-view').classList.add('hidden');
        document.getElementById('backup-codes-overlay').classList.remove('hidden');
    }

    function closeBackupCodesModal() {
        document.getElementById('backup-codes-overlay').classList.add('hidden');
        backupCodesTargetUserId = null;
    }

    /**
     * Entry point for a user's OWN first-login reveal (see
     * layouts/app.blade.php, triggered off AuthController::login()'s
     * new_backup_codes flash) — skips the password-gate view entirely
     * and goes straight to the code list, since there's no one to gate
     * against: this is the account holder looking at their own codes.
     */
    function openBackupCodesModalWithCodes(title, codes, note) {
        backupCodesTargetUserId = null;
        document.getElementById('backup-codes-title').textContent = title;

        const noteEl = document.getElementById('backup-codes-save-now-note');
        noteEl.textContent = note;
        noteEl.classList.remove('hidden');

        renderBackupCodes(codes.map((code) => ({ code, used_at: null })));
        document.getElementById('backup-codes-password-view').classList.add('hidden');
        document.getElementById('backup-codes-list-view').classList.remove('hidden');
        document.getElementById('backup-codes-overlay').classList.remove('hidden');
    }

    function submitBackupCodesPassword() {
        const password = document.getElementById('backup-codes-password-input').value;
        const errorEl = document.getElementById('backup-codes-error');
        const verifyBtn = document.getElementById('backup-codes-verify-btn');
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

        errorEl.classList.add('hidden');
        verifyBtn.disabled = true;

        fetch(`/admin/users/${backupCodesTargetUserId}/backup-codes`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrfToken,
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            body: JSON.stringify({ password }),
        })
            .then((res) => res.json().then((data) => ({ status: res.status, data })))
            .then(({ status, data }) => {
                verifyBtn.disabled = false;
                if (status !== 200) {
                    errorEl.textContent = data.message || 'Could not verify — try again.';
                    errorEl.classList.remove('hidden');
                    return;
                }
                renderBackupCodes(data.codes);
                document.getElementById('backup-codes-password-view').classList.add('hidden');
                document.getElementById('backup-codes-list-view').classList.remove('hidden');
            })
            .catch(() => {
                verifyBtn.disabled = false;
                errorEl.textContent = 'Something went wrong — check your connection and try again.';
                errorEl.classList.remove('hidden');
            });
    }

    function renderBackupCodes(codes) {
        const list = document.getElementById('backup-codes-list');
        list.innerHTML = '';
        codes.forEach((c) => {
            const li = document.createElement('li');
            li.className = 'flex items-center justify-between rounded-lg border border-surface-200 px-3 py-2';
            const codeSpan = document.createElement('span');
            codeSpan.className = 'font-mono text-sm tracking-wide ' + (c.used_at ? 'text-surface-400 line-through' : 'text-surface-900 font-semibold');
            codeSpan.textContent = c.code;
            const statusSpan = document.createElement('span');
            if (c.used_at) {
                statusSpan.className = 'text-[11px] text-surface-400';
                statusSpan.textContent = 'Used ' + c.used_at;
            } else {
                statusSpan.className = 'text-[11px] font-medium text-approved-700';
                statusSpan.textContent = 'Available';
            }
            li.appendChild(codeSpan);
            li.appendChild(statusSpan);
            list.appendChild(li);
        });
    }
</script>
