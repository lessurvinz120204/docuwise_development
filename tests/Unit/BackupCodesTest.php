<?php

use App\Models\User;
use App\Models\UserBackupCode;
use Illuminate\Support\Facades\Crypt;

/**
 * Covers User::generateBackupCodes()/verifyBackupCode() in isolation —
 * the login-flow integration (AuthController) and the Admin password-
 * gated view (AdminController::backupCodes()) each have their own
 * dedicated feature tests.
 */
it('generates the configured number of codes', function () {
    $user = User::factory()->originator()->create();

    $user->generateBackupCodes();

    expect($user->backupCodes()->count())->toBe(User::BACKUP_CODE_COUNT);
    expect($user->backupCodes()->unused()->count())->toBe(User::BACKUP_CODE_COUNT);
});

it('stores codes encrypted, not as plain text, and not one-way hashed', function () {
    $user = User::factory()->originator()->create();
    $user->generateBackupCodes();

    $raw = UserBackupCode::where('user_id', $user->user_id)->first();

    // Not plain text.
    expect($raw->code)->not->toMatch('/^\d{6}$/');
    // But recoverable (unlike Hash::make, which can never be reversed) —
    // this is the deliberate, discussed trade-off that lets Admin look
    // codes back up.
    expect(Crypt::decryptString($raw->code))->toMatch('/^\d{6}$/');
});

it('verifies a correct code and marks it used', function () {
    $user = User::factory()->originator()->create();
    $user->generateBackupCodes();
    $code = Crypt::decryptString($user->backupCodes()->first()->code);

    expect($user->verifyBackupCode($code))->toBeTrue();

    $used = UserBackupCode::where('user_id', $user->user_id)->whereNotNull('used_at')->first();
    expect($used)->not->toBeNull();
    expect(Crypt::decryptString($used->code))->toBe($code);
});

it('rejects a code that was already used', function () {
    $user = User::factory()->originator()->create();
    $user->generateBackupCodes();
    $code = Crypt::decryptString($user->backupCodes()->first()->code);

    expect($user->verifyBackupCode($code))->toBeTrue();
    expect($user->verifyBackupCode($code))->toBeFalse();
});

it('rejects a code that never belonged to this account', function () {
    $user = User::factory()->originator()->create();
    $user->generateBackupCodes();

    expect($user->verifyBackupCode('999999'))->toBeFalse();
});

it("rejects one user's code when checked against a different user's account", function () {
    $userA = User::factory()->originator()->create();
    $userB = User::factory()->originator()->create();
    $userA->generateBackupCodes();
    $userB->generateBackupCodes();

    $codeA = Crypt::decryptString($userA->backupCodes()->first()->code);

    expect($userB->verifyBackupCode($codeA))->toBeFalse();
    // And it's still valid for its real owner, untouched.
    expect($userA->verifyBackupCode($codeA))->toBeTrue();
});

it('automatically generates a fresh batch the instant the last code is used', function () {
    $user = User::factory()->originator()->create();
    $user->generateBackupCodes();

    $codes = $user->backupCodes()->get()->map(fn ($c) => Crypt::decryptString($c->code));
    foreach ($codes as $code) {
        expect($user->verifyBackupCode($code))->toBeTrue();
    }

    expect($user->backupCodes()->unused()->count())->toBe(User::BACKUP_CODE_COUNT);
    // The old, fully-used batch doesn't linger alongside the new one.
    expect($user->backupCodes()->count())->toBe(User::BACKUP_CODE_COUNT);
});

it('trims whitespace on the entered code', function () {
    $user = User::factory()->originator()->create();
    $user->generateBackupCodes();
    $code = Crypt::decryptString($user->backupCodes()->first()->code);

    expect($user->verifyBackupCode('  ' . $code . '  '))->toBeTrue();
});
