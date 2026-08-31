<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;

/**
 * Covers the two places Sign In Backup Codes actually get used: logging
 * in with one (AuthController::login()) and an Admin looking a user's
 * current codes up (AdminController::backupCodes()). User model-level
 * behavior (generation, single-use, cross-account isolation) is covered
 * in tests/Unit/BackupCodesTest.php.
 */
function backupCodeUser(array $attributes = []): User
{
    $user = User::factory()->originator()->create(array_merge([
        'password_hash' => Hash::make('correct-password'),
    ], $attributes));
    $user->generateBackupCodes();

    return $user;
}

it('logs in with a backup code instead of the emailed code', function () {
    $user = backupCodeUser(['email' => 'backup@example.test']);
    $code = Crypt::decryptString($user->backupCodes()->first()->code);

    $this->post(route('login.attempt'), ['email' => 'backup@example.test', 'password' => 'correct-password', 'code' => $code])
        ->assertRedirect(route('originator.dashboard'));

    $this->assertAuthenticatedAs($user);
});

it('does not require requesting an emailed code first to use a backup code', function () {
    $user = backupCodeUser(['email' => 'backup@example.test']);
    $code = Crypt::decryptString($user->backupCodes()->first()->code);

    // No login.request-code call anywhere in this test — proves a
    // backup code works standalone, not gated on ever having asked for
    // the emailed one.
    $this->post(route('login.attempt'), ['email' => 'backup@example.test', 'password' => 'correct-password', 'code' => $code])
        ->assertRedirect(route('originator.dashboard'));
});

it('a backup code cannot be reused for a second login', function () {
    $user = backupCodeUser(['email' => 'backup@example.test']);
    $code = Crypt::decryptString($user->backupCodes()->first()->code);

    $this->post(route('login.attempt'), ['email' => 'backup@example.test', 'password' => 'correct-password', 'code' => $code])
        ->assertRedirect(route('originator.dashboard'));

    auth()->logout();

    $this->post(route('login.attempt'), ['email' => 'backup@example.test', 'password' => 'correct-password', 'code' => $code])
        ->assertSessionHasErrors('code');
    $this->assertGuest();
});

it('a wrong backup code counts against the same lockout as a wrong password', function () {
    backupCodeUser(['email' => 'backup@example.test']);

    for ($i = 0; $i < 5; $i++) {
        $this->post(route('login.attempt'), ['email' => 'backup@example.test', 'password' => 'correct-password', 'code' => '999999']);
    }

    $response = $this->post(route('login.attempt'), ['email' => 'backup@example.test', 'password' => 'correct-password', 'code' => '999999']);

    $response->assertSessionHasErrors('email');
    expect(session('errors')->get('email')[0])->toContain('Too many login attempts');
});

it("does not accept another user's backup code", function () {
    $userA = backupCodeUser(['email' => 'usera@example.test']);
    $userB = backupCodeUser(['email' => 'userb@example.test']);
    $codeA = Crypt::decryptString($userA->backupCodes()->first()->code);

    $this->post(route('login.attempt'), ['email' => 'userb@example.test', 'password' => 'correct-password', 'code' => $codeA])
        ->assertSessionHasErrors('code');
    $this->assertGuest();
});

// --- Admin-side password-gated view ---

it("lets an Admin view a user's backup codes after verifying that user's own current password", function () {
    $admin = User::factory()->admin()->create();
    $target = backupCodeUser(['full_name' => 'Target Person']);

    $response = $this->actingAs($admin)->postJson(route('admin.users.backup-codes', $target), ['password' => 'correct-password']);

    $response->assertOk();
    $codes = $response->json('codes');
    expect($codes)->toHaveCount(User::BACKUP_CODE_COUNT);
    expect($codes[0])->toHaveKeys(['code', 'used_at']);
});

it("rejects the admin's own password — must be the target user's", function () {
    $admin = User::factory()->admin()->create(['password_hash' => Hash::make('admin-own-password')]);
    $target = backupCodeUser();

    $response = $this->actingAs($admin)->postJson(route('admin.users.backup-codes', $target), ['password' => 'admin-own-password']);

    $response->assertStatus(422);
});

it('logs both a successful and a denied attempt to view backup codes', function () {
    $admin = User::factory()->admin()->create();
    $target = backupCodeUser();

    $this->actingAs($admin)->postJson(route('admin.users.backup-codes', $target), ['password' => 'wrong']);
    $this->actingAs($admin)->postJson(route('admin.users.backup-codes', $target), ['password' => 'correct-password']);

    expect(AuditLog::where('action_type', 'view_backup_codes_denied')->exists())->toBeTrue();
    expect(AuditLog::where('action_type', 'view_backup_codes')->exists())->toBeTrue();
});

it('lazily generates a first batch for an account that predates this feature', function () {
    $admin = User::factory()->admin()->create();
    // No generateBackupCodes() call — simulates a pre-existing account.
    $target = User::factory()->originator()->create(['password_hash' => Hash::make('correct-password')]);
    expect($target->backupCodes()->count())->toBe(0);

    $response = $this->actingAs($admin)->postJson(route('admin.users.backup-codes', $target), ['password' => 'correct-password']);

    $response->assertOk();
    expect($response->json('codes'))->toHaveCount(User::BACKUP_CODE_COUNT);
});

it('rate limits repeated wrong-password guesses against the same target account', function () {
    $admin = User::factory()->admin()->create();
    $target = backupCodeUser();

    for ($i = 0; $i < 5; $i++) {
        $this->actingAs($admin)->postJson(route('admin.users.backup-codes', $target), ['password' => 'wrong']);
    }

    $response = $this->actingAs($admin)->postJson(route('admin.users.backup-codes', $target), ['password' => 'wrong']);

    $response->assertStatus(429);
});
