<?php

use App\Mail\TwoFactorCodeMail;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * Every account (Admin, Approver, Originator — no exceptions, per the
 * explicit ask) needs a second, emailed one-time code on top of a correct
 * password before a real session starts. Everything lives on the one
 * login page (email, password, code, "Get Code") — there is no separate
 * two-factor screen or redirect. "Get Code" (AuthController::
 * requestCode()) validates credentials WITHOUT logging in and, if
 * correct, emails a code; the actual login only ever completes through
 * login() itself, which re-checks the password AND the code together in
 * one request. See User::generateTwoFactorCode()/verifyTwoFactorCode()/
 * clearTwoFactorCode().
 */
function twoFactorUser(array $attributes = []): User
{
    return User::factory()->originator()->create(array_merge([
        'password_hash' => Hash::make('correct-password'),
    ], $attributes));
}

/** Hits "Get Code" (requestCode()) and returns the plain code that was emailed. */
function requestCodeFor($test, string $email, string $password): string
{
    $test->postJson(route('login.request-code'), ['email' => $email, 'password' => $password])
        ->assertOk()
        ->assertJsonStructure(['expiresIn']);

    $code = null;
    Mail::assertSent(TwoFactorCodeMail::class, function ($mail) use (&$code) {
        $code = $mail->code;

        return true;
    });

    return $code;
}

it('rejects "Get Code" for a wrong password without sending anything', function () {
    twoFactorUser(['email' => 'twofactor@example.test']);
    Mail::fake();

    $response = $this->postJson(route('login.request-code'), ['email' => 'twofactor@example.test', 'password' => 'wrong-password']);

    $response->assertStatus(422);
    Mail::assertNothingQueued();
});

it('sends a code for a correct email/password without logging in', function () {
    twoFactorUser(['email' => 'twofactor@example.test']);
    Mail::fake();

    requestCodeFor($this, 'twofactor@example.test', 'correct-password');

    $this->assertGuest();
});

it('does not complete login on email+password alone — the code is required too', function () {
    twoFactorUser(['email' => 'twofactor@example.test']);

    $response = $this->post(route('login.attempt'), [
        'email' => 'twofactor@example.test',
        'password' => 'correct-password',
        'code' => '',
    ]);

    $response->assertSessionHasErrors('code');
    $this->assertGuest();
});

it('completes login and starts a real session once the correct code is entered', function () {
    $user = twoFactorUser(['email' => 'twofactor@example.test']);
    Mail::fake();

    $code = requestCodeFor($this, 'twofactor@example.test', 'correct-password');

    $this->post(route('login.attempt'), ['email' => 'twofactor@example.test', 'password' => 'correct-password', 'code' => $code])
        ->assertRedirect(route('originator.dashboard'));

    $this->assertAuthenticatedAs($user);
});

it('rejects a wrong code even with the correct password, and keeps the visitor a guest', function () {
    twoFactorUser(['email' => 'twofactor@example.test']);
    Mail::fake();

    requestCodeFor($this, 'twofactor@example.test', 'correct-password');

    $response = $this->post(route('login.attempt'), ['email' => 'twofactor@example.test', 'password' => 'correct-password', 'code' => '000000']);

    $response->assertSessionHasErrors('code');
    $this->assertGuest();
});

it('rejects a code that has expired', function () {
    twoFactorUser(['email' => 'twofactor@example.test']);
    Mail::fake();

    $code = requestCodeFor($this, 'twofactor@example.test', 'correct-password');

    $this->travel(User::TWO_FACTOR_CODE_VALIDITY_SECONDS + 1)->seconds();

    $response = $this->post(route('login.attempt'), ['email' => 'twofactor@example.test', 'password' => 'correct-password', 'code' => $code]);

    $response->assertSessionHasErrors('code');
    $this->assertGuest();
});

it('invalidates the old code once a new one is requested', function () {
    $user = twoFactorUser(['email' => 'twofactor@example.test']);
    Mail::fake();

    $firstCode = requestCodeFor($this, 'twofactor@example.test', 'correct-password');
    $secondCode = requestCodeFor($this, 'twofactor@example.test', 'correct-password');

    expect($secondCode)->not->toBe($firstCode);

    $this->post(route('login.attempt'), ['email' => 'twofactor@example.test', 'password' => 'correct-password', 'code' => $firstCode])
        ->assertSessionHasErrors('code');
    $this->assertGuest();

    $this->post(route('login.attempt'), ['email' => 'twofactor@example.test', 'password' => 'correct-password', 'code' => $secondCode])
        ->assertRedirect(route('originator.dashboard'));
    $this->assertAuthenticatedAs($user);
});

it('a code cannot be reused after a successful login', function () {
    $user = twoFactorUser(['email' => 'twofactor@example.test']);
    Mail::fake();

    $code = requestCodeFor($this, 'twofactor@example.test', 'correct-password');

    $this->post(route('login.attempt'), ['email' => 'twofactor@example.test', 'password' => 'correct-password', 'code' => $code])
        ->assertRedirect(route('originator.dashboard'));

    auth()->logout();

    $this->post(route('login.attempt'), ['email' => 'twofactor@example.test', 'password' => 'correct-password', 'code' => $code])
        ->assertSessionHasErrors('code');
    $this->assertGuest();
});

it('a wrong code counts against the same lockout as a wrong password, not a separate uncapped counter', function () {
    twoFactorUser(['email' => 'twofactor@example.test']);
    Mail::fake();

    requestCodeFor($this, 'twofactor@example.test', 'correct-password');

    for ($i = 0; $i < 5; $i++) {
        $this->post(route('login.attempt'), ['email' => 'twofactor@example.test', 'password' => 'correct-password', 'code' => '000000']);
    }

    $response = $this->post(route('login.attempt'), ['email' => 'twofactor@example.test', 'password' => 'correct-password', 'code' => '000000']);

    $response->assertSessionHasErrors('email');
    expect(session('errors')->get('email')[0])->toContain('Too many login attempts');
});

it('rate limits repeated wrong-password guesses via "Get Code" itself', function () {
    twoFactorUser(['email' => 'twofactor@example.test']);

    for ($i = 0; $i < 5; $i++) {
        $this->postJson(route('login.request-code'), ['email' => 'twofactor@example.test', 'password' => 'wrong-password']);
    }

    $response = $this->postJson(route('login.request-code'), ['email' => 'twofactor@example.test', 'password' => 'wrong-password']);

    $response->assertStatus(429);
});
