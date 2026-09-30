<?php

use App\Http\Controllers\Admin\BranchController;
use App\Http\Controllers\Auth\AuthController;
use Illuminate\Support\Facades\Route;

/*
| Admin SPA routes use the `web` group (session cookie + CSRF), prefixed /api.
| Pipeline (ARCHITECTURE §4): auth → tenant (from principal) → role/permission
| → subscription → tenant-scoped route-model binding → idempotency.
*/

Route::middleware('web')->group(function (): void {
    Route::get('auth/csrf', [AuthController::class, 'csrf']);
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware(['auth:web', 'tenant.user'])->group(function (): void {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::put('me/password', [AuthController::class, 'changePassword'])->middleware('throttle:login');

        // Client admin (tenant users only).
        Route::prefix('admin')->middleware(['tenant.member', 'idempotency'])->group(function (): void {
            Route::middleware('subscription.active')->group(function (): void {
                Route::get('branches', [BranchController::class, 'index']);
                Route::get('branches/{branch}', [BranchController::class, 'show']);
            });
        });

        // Super Admin (platform) — Phase 5.
        Route::prefix('super')->middleware(['super.admin', 'idempotency'])->group(function (): void {
            //
        });
    });
});
