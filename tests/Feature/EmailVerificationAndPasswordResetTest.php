<?php

use App\Events\UserVerified;
use App\Mail\ResetPasswordMail;
use App\Mail\TwoFactorCodeMail;
use App\Mail\VerifyAccountMail;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;

/**
 * Coverage for the auth overhaul: login switched from username to email,
 * login blocked entirely until the account's email is verified (not just
 * flagged — see AuthController::login()), and self-service password reset
 * now that verified email makes a reset link trustworthy to send.
 */
function verifiedUser(array $attributes = []): User
{
    return User::factory()->originator()->create(array_merge(['password_hash' => Hash::make('correct-password')], $attributes));
}

function unverifiedUser(array $attributes = []): User
{
    return User::factory()->unverified()->originator()->create(array_merge(['password_hash' => Hash::make('correct-password')], $attributes));
}

/**
 * Drives the whole login (email+password via "Get Code", then submitting
 * the emailed code alongside them) — for the tests that specifically need
 * to prove a session actually starts, not just that the password check
 * passed. Everything lives on the one login page/form now — see
 * AuthController::login()/requestCode(). $test is the running test case
 * explicitly, since this helper (a plain top-level function, not a
 * closure) has no implicit $this the way an it(...) callback does.
 */
function completeTwoFactorLogin($test, string $email, string $password)
{
    Mail::fake();

    $test->postJson(route('login.request-code'), ['email' => $email, 'password' => $password])
        ->assertOk();

    $code = null;
    Mail::assertSent(TwoFactorCodeMail::class, function ($mail) use (&$code) {
        $code = $mail->code;

        return true;
    });

    return $test->post(route('login.attempt'), ['email' => $email, 'password' => $password, 'code' => $code]);
}

it('logs in with email and password once verified', function () {
    $user = verifiedUser(['email' => 'verified@example.test']);

    completeTwoFactorLogin($this, 'verified@example.test', 'correct-password')
        ->assertRedirect(route('originator.dashboard'));

    $this->assertAuthenticatedAs($user);
});

it('blocks login for an unverified account even with correct credentials', function () {
    unverifiedUser(['email' => 'unverified@example.test']);

    $response = $this->post(route('login.attempt'), ['email' => 'unverified@example.test', 'password' => 'correct-password']);

    $response->assertSessionHasErrors('email');
    expect(session('errors')->get('email')[0])->toContain('verify your email');
    $this->assertGuest();
});

it('clicking a valid verification link marks the account verified and does not log it in automatically', function () {
    $user = unverifiedUser();

    $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
        'id' => $user->user_id,
        'hash' => sha1($user->email),
    ]);

    $response = $this->get($url);

    $response->assertRedirect(route('login'));
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    $this->assertGuest(); // link alone doesn't authenticate — a separate, deliberate step from verifying

    // And now login actually works.
    completeTwoFactorLogin($this, $user->email, 'correct-password')
        ->assertRedirect(route('originator.dashboard'));
});

it('broadcasts UserVerified so the admin Users page can drop the "Unverified" badge live', function () {
    Event::fake([UserVerified::class]);
    $user = unverifiedUser();

    $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
        'id' => $user->user_id,
        'hash' => sha1($user->email),
    ]);

    $this->get($url);

    Event::assertDispatched(UserVerified::class, fn ($e) => $e->user->is($user));
});

it('does not re-broadcast UserVerified for a link clicked twice', function () {
    Event::fake([UserVerified::class]);
    $user = unverifiedUser();

    $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
        'id' => $user->user_id,
        'hash' => sha1($user->email),
    ]);

    $this->get($url);
    $this->get($url); // clicked again — link is still validly signed within its window

    Event::assertDispatchedTimes(UserVerified::class, 1);
});

it('shows a Resend-verification action for a pending account, gone once verified', function () {
    $admin = User::factory()->admin()->create();
    $pending = unverifiedUser();

    $this->actingAs($admin)
        ->get(route('admin.users'))
        ->assertOk()
        ->assertSee('Unverified')
        ->assertSee(route('admin.users.resend-verification', $pending), false);

    $pending->markEmailAsVerified();

    $this->actingAs($admin)
        ->get(route('admin.users'))
        ->assertOk()
        ->assertDontSee(route('admin.users.resend-verification', $pending), false);
});

it('the users refresh endpoint returns only the table fragment, not a full page', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->get(route('admin.users.refresh'));

    $response->assertOk()->assertDontSee('<html', false);
});

it('the users poll endpoint reports unverified account ids', function () {
    $admin = User::factory()->admin()->create();
    $pending = unverifiedUser();

    $this->actingAs($admin)
        ->getJson(route('admin.users.poll'))
        ->assertOk()
        ->assertJsonFragment(['unverified_ids' => [$pending->user_id]]);
});

it('renders the login email field as type=text, not type=email, so the browser never silently blocks an invalid value before the server sees it', function () {
    $response = $this->get(route('login'));

    $response->assertOk()->assertSee('type="text" inputmode="email"', false);
});

it('rejects a verification link with a tampered hash', function () {
    $user = unverifiedUser();

    $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
        'id' => $user->user_id,
        'hash' => sha1('someone-else@example.test'),
    ]);

    $this->get($url)->assertForbidden();
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('rejects an expired verification link', function () {
    $user = unverifiedUser();

    $url = URL::temporarySignedRoute('verification.verify', now()->subMinute(), [
        'id' => $user->user_id,
        'hash' => sha1($user->email),
    ]);

    $this->get($url)->assertForbidden();
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('creating an account via the admin sends a verification email and starts it unverified', function () {
    Mail::fake();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post(route('admin.users.store'), [
        'username' => 'newapprover',
        'full_name' => 'New Approver',
        'email' => 'newapprover@example.test',
        'role' => 'originator',
        'password' => 'Qz8kVn4RTwmp',
    ])->assertSessionHas('status');

    $user = User::where('username', 'newapprover')->firstOrFail();
    expect($user->hasVerifiedEmail())->toBeFalse();
    Mail::assertQueued(VerifyAccountMail::class, fn ($mail) => $mail->user->is($user));
});

it('generates the verification link from APP_URL, not whatever host the admin happened to browse from', function () {
    // Regression test for the actual bug behind "admin clicks Resend,
    // link is broken, but a CLI-triggered resend works fine": Laravel's
    // URL generator derives absolute URLs from the CURRENT REQUEST's own
    // Host header for real HTTP requests (only CLI, with no bound
    // request, falls back to config('app.url')) — unless something
    // forces the root explicitly (see AppServiceProvider::boot()). An
    // admin browsing via a different host than APP_URL (e.g. 127.0.0.1
    // on the dev machine, while APP_URL is the LAN IP every other device
    // needs) would otherwise bake that wrong host into the emailed link.
    Mail::fake();
    $admin = User::factory()->admin()->create();
    $target = unverifiedUser();

    $this->actingAs($admin)->call(
        'POST',
        route('admin.users.resend-verification', $target),
        server: ['HTTP_HOST' => '127.0.0.1:9999']
    )->assertSessionHas('status');

    $configuredHost = parse_url(config('app.url'), PHP_URL_HOST);

    Mail::assertQueued(VerifyAccountMail::class, function ($mail) use ($configuredHost) {
        return str_contains($mail->verificationUrl, $configuredHost)
            && !str_contains($mail->verificationUrl, '127.0.0.1:9999');
    });
});

it('lets an admin resend the verification email for an unverified account', function () {
    Mail::fake();
    $admin = User::factory()->admin()->create();
    $target = unverifiedUser();

    $this->actingAs($admin)
        ->post(route('admin.users.resend-verification', $target))
        ->assertSessionHas('status');

    Mail::assertQueued(VerifyAccountMail::class, fn ($mail) => $mail->user->is($target));
});

it('rejects resending a verification email for an already-verified account', function () {
    $admin = User::factory()->admin()->create();
    $target = verifiedUser();

    $this->actingAs($admin)
        ->post(route('admin.users.resend-verification', $target))
        ->assertStatus(409);
});

it('sends a password reset email to a real registered account', function () {
    Mail::fake();
    $user = verifiedUser(['email' => 'resetme@example.test']);

    $this->post(route('password.email'), ['email' => 'resetme@example.test'])
        ->assertSessionHas('status');

    Mail::assertQueued(ResetPasswordMail::class, fn ($mail) => $mail->user->is($user));
});

it('does not reveal whether an email is registered when requesting a reset', function () {
    Mail::fake();

    $response = $this->post(route('password.email'), ['email' => 'nobody-registered@example.test']);

    // Same success response either way — no distinct error for "no such account".
    $response->assertSessionHas('status');
    Mail::assertNothingQueued();
});

it('resets the password with a valid token and allows logging in with the new password', function () {
    $user = verifiedUser(['email' => 'canreset@example.test']);
    $token = Password::createToken($user);

    $this->post(route('password.update'), [
        'token' => $token,
        'email' => 'canreset@example.test',
        'password' => 'A-brand-new-password1',
        'password_confirmation' => 'A-brand-new-password1',
    ])->assertRedirect(route('login'));

    // "Get Code" succeeding is exactly what proves the new password was
    // actually accepted (see AuthController::requestCode(), which
    // validates credentials before sending anything) — that's what this
    // test is actually verifying, not a full login.
    Mail::fake();
    $this->postJson(route('login.request-code'), ['email' => 'canreset@example.test', 'password' => 'A-brand-new-password1'])
        ->assertOk();
});

it('rejects resetting the password with an invalid token', function () {
    $user = verifiedUser(['email' => 'badtoken@example.test']);

    $response = $this->post(route('password.update'), [
        'token' => 'not-a-real-token',
        'email' => 'badtoken@example.test',
        'password' => 'A-brand-new-password1',
        'password_confirmation' => 'A-brand-new-password1',
    ]);

    $response->assertSessionHasErrors('email');
    $this->post(route('login.attempt'), ['email' => 'badtoken@example.test', 'password' => 'A-brand-new-password1'])
        ->assertSessionHasErrors(); // old password never changed
});
