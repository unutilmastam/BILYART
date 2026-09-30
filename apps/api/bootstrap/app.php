<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\Telegram\WebhookController;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\AuthenticateDevice;
use App\Http\Middleware\AuthenticateTablet;
use App\Http\Middleware\EnsureActiveSubscription;
use App\Http\Middleware\EnsureIdempotency;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureTenantUser;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\ResolveUserTenant;
use App\Http\Middleware\SecurityHeaders;
use App\Support\Http\ApiErrorRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        then: function (): void {
            Route::middleware('api')->prefix('device/v1')->group(base_path('routes/device.php'));
            // Monitoring (spec §56): /health, /health/{db|storage|messaging|backups}. Stateless.
            Route::middleware('api')->get('health/{check?}', HealthController::class)
                ->where('check', 'db|storage|messaging|backups');
            // Telegram webhook: stateless, authenticated by Telegram's secret header (no session/CSRF).
            Route::middleware(['api', 'throttle:telegram'])->post('telegram/webhook/{integration}', WebhookController::class)
                ->where('integration', '[0-9A-HJKMNP-TV-Z]{26}');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);
        $middleware->append(SecurityHeaders::class);

        $middleware->alias([
            // Resolves the tenant from the authenticated user (never from input).
            'tenant.user' => ResolveUserTenant::class,
            'tenant.member' => EnsureTenantUser::class,
            'super.admin' => EnsureSuperAdmin::class,
            'subscription.active' => EnsureActiveSubscription::class,
            'perm' => RequirePermission::class,
            'idempotency' => EnsureIdempotency::class,
            'auth.tablet' => AuthenticateTablet::class,
            'auth.device' => AuthenticateDevice::class,
        ]);

        // Tenant context must exist before implicit route-model binding runs.
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveUserTenant::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, AuthenticateTablet::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, AuthenticateDevice::class);
        // Role gates answer 403 before a foreign/unknown record could answer 404.
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureTenantUser::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureSuperAdmin::class);

        // JSON API: never redirect guests to a login page.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        ApiErrorRenderer::register($exceptions);
    })->create();
