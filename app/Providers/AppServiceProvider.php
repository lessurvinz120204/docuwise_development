<?php

namespace App\Providers;

use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\NotificationRecord;
use App\Policies\DocumentAssignmentPolicy;
use App\Policies\DocumentRepositoryPolicy;
use App\Policies\NotificationRecordPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Mailer\Bridge\Brevo\Transport\BrevoTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Without this, Laravel derives every generated absolute URL (signed
        // verification/reset links included) from the CURRENT request's own
        // host header — not from APP_URL — for any real HTTP request (only
        // CLI/tinker, with no bound request, actually falls back to
        // config('app.url')). That's exactly why admin-triggered "Resend
        // verification" produced a broken link while a tinker-triggered
        // resend worked: the admin was browsing via 127.0.0.1:8000, so
        // Laravel built the email link from THAT host instead of the LAN
        // IP every other device needs. Forcing the root here makes every
        // generated URL consistent regardless of which host a given
        // request happened to arrive on.
        URL::forceRootUrl(config('app.url'));

        // App-wide pagination styling — see the view's own docblock for
        // why the framework's built-in Tailwind view (living in vendor/,
        // outside Tailwind's content-scan glob) rendered completely
        // unstyled everywhere it was used.
        Paginator::defaultView('vendor.pagination.custom');

        // Custom mailer — Laravel has no built-in Brevo driver. Brevo's
        // *SMTP* transport times out from Railway (outbound SMTP appears
        // blocked there regardless of provider — Resend's API worked fine
        // in the same environment), so this uses Brevo's HTTP API instead
        // via Symfony's brevo-mailer bridge. Brevo was chosen over Resend
        // because it only requires verifying the individual sender address
        // (a one-click email confirmation), not owning/verifying a DNS
        // domain — this app sends from real personal Gmail addresses with
        // no domain of its own.
        Mail::extend('brevo', fn (array $config) => (new BrevoTransportFactory())
            ->create(new Dsn('brevo+api', 'default', $config['key'] ?? null)));

        $this->registerRateLimiters();

        // Explicit registration rather than relying on Laravel's naming-
        // convention auto-discovery — matches this app's existing preference
        // for explicit, readable authorization (see RoleMiddleware / the
        // routes/web.php header comment on strict RBAC) over implicit
        // "it just works if you name things right" conventions.
        Gate::policy(DocumentRepository::class, DocumentRepositoryPolicy::class);
        Gate::policy(DocumentAssignment::class, DocumentAssignmentPolicy::class);
        Gate::policy(NotificationRecord::class, NotificationRecordPolicy::class);
    }

    /**
     * Named rate limiters, one per tier of route already in use across
     * routes/web.php and routes/api.php (previously 45 separate inline
     * throttle:N,1 declarations, one written by hand at each route). Same
     * limits, same behavior — this only centralizes them so a new route
     * added later references a named tier ('mutations', 'polling', ...)
     * instead of needing someone to remember and retype the right numbers,
     * and so every route in a tier can be retuned in one place.
     *
     * Keyed by user ID when authenticated, falling back to IP — matches
     * Laravel's own default `throttle:N,1` behavior (Illuminate\Routing\
     * Middleware\ThrottleRequests resolves the same way), so this is a
     * pure rename, not a behavior change.
     */
    private function registerRateLimiters(): void
    {
        $byUserOrIp = fn (Request $request) => $request->user()?->getAuthIdentifier() ?? $request->ip();

        // Password reset request/update — the most sensitive, lowest-volume tier.
        RateLimiter::for('auth-sensitive', fn (Request $request) => Limit::perMinute(5)->by($byUserOrIp($request)));

        // Login attempts and signed one-time links (email verification).
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)->by($byUserOrIp($request)));

        // State-changing endpoints: uploads, resubmissions, decisions, ML
        // staging, legacy archive import.
        RateLimiter::for('mutations', fn (Request $request) => Limit::perMinute(20)->by($byUserOrIp($request)));

        // Read-only poll/refresh endpoints the various dashboards hit every
        // few seconds while open — the majority of this app's routes.
        RateLimiter::for('polling', fn (Request $request) => Limit::perMinute(30)->by($byUserOrIp($request)));

        // Document-viewer presence heartbeat/leave beacons — highest volume,
        // fires on a short client-side interval while the viewer is open.
        RateLimiter::for('presence', fn (Request $request) => Limit::perMinute(60)->by($byUserOrIp($request)));
    }
}
