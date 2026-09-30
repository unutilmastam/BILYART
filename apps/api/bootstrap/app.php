<?php

use App\Http\Middleware\AssignRequestId;
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

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
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
        ]);

        // Tenant context must exist before implicit route-model binding runs.
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveUserTenant::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, AuthenticateTablet::class);

        // JSON API: never redirect guests to a login page.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        ApiErrorRenderer::register($exceptions);
    })->create();
