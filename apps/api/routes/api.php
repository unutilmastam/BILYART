<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\Admin\BranchController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\SuperAdmin;
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
            Route::get('subscription', Admin\SubscriptionController::class);

            Route::middleware('subscription.active')->group(function (): void {
                Route::get('branches', [BranchController::class, 'index']);
                Route::get('branches/{branch}', [BranchController::class, 'show']);
                Route::get('branches/{branch}/working-hours', [BranchController::class, 'workingHours']);
                Route::get('branches/{branch}/closed-days', [BranchController::class, 'closedDays']);
                Route::middleware('perm:branches.manage')->group(function (): void {
                    Route::post('branches', [BranchController::class, 'store']);
                    Route::patch('branches/{branch}', [BranchController::class, 'update']);
                    Route::delete('branches/{branch}', [BranchController::class, 'destroy']);
                });
                Route::middleware('perm:working_hours.manage')->group(function (): void {
                    Route::put('branches/{branch}/working-hours', [BranchController::class, 'setWorkingHours']);
                    Route::post('branches/{branch}/closed-days', [BranchController::class, 'addClosedDay']);
                    Route::delete('branches/{branch}/closed-days/{day}', [BranchController::class, 'removeClosedDay']);
                });

                Route::middleware('perm:tables.view')->group(function (): void {
                    Route::get('tables', [Admin\TableController::class, 'index']);
                    Route::get('tables/{table}', [Admin\TableController::class, 'show']);
                });
                Route::middleware('perm:tables.manage')->group(function (): void {
                    Route::post('tables', [Admin\TableController::class, 'store']);
                    Route::patch('tables/{table}', [Admin\TableController::class, 'update']);
                    Route::delete('tables/{table}', [Admin\TableController::class, 'destroy']);
                });

                Route::middleware('perm:pricing.manage')->group(function (): void {
                    Route::get('pricing-plans', [Admin\PricingPlanController::class, 'index']);
                    Route::post('pricing-plans', [Admin\PricingPlanController::class, 'store']);
                    Route::get('pricing-plans/{plan}', [Admin\PricingPlanController::class, 'show']);
                    Route::patch('pricing-plans/{plan}', [Admin\PricingPlanController::class, 'update']);
                    Route::delete('pricing-plans/{plan}', [Admin\PricingPlanController::class, 'destroy']);
                });

                Route::middleware('perm:users.manage')->group(function (): void {
                    Route::get('users', [Admin\UserController::class, 'index']);
                    Route::post('users', [Admin\UserController::class, 'store']);
                    Route::get('users/{user}', [Admin\UserController::class, 'show']);
                    Route::patch('users/{user}', [Admin\UserController::class, 'update']);
                    Route::post('users/{user}/deactivate', [Admin\UserController::class, 'deactivate']);
                });

                Route::middleware('perm:tenant.settings')->group(function (): void {
                    Route::get('settings', [Admin\SettingsController::class, 'show']);
                    Route::put('settings', [Admin\SettingsController::class, 'update']);
                    Route::get('audit-logs', Admin\AuditLogController::class);
                });
            });
        });

        // Super Admin (platform) — Phase 5.
        Route::prefix('super')->middleware(['super.admin', 'idempotency'])->group(function (): void {
            Route::get('dashboard', SuperAdmin\DashboardController::class)->middleware('perm:platform.tenants');
            Route::middleware('perm:platform.tenants')->group(function (): void {
                Route::get('tenants', [SuperAdmin\TenantController::class, 'index']);
                Route::post('tenants', [SuperAdmin\TenantController::class, 'store']);
                Route::get('tenants/{tenant}', [SuperAdmin\TenantController::class, 'show']);
                Route::patch('tenants/{tenant}', [SuperAdmin\TenantController::class, 'update']);
                Route::post('tenants/{tenant}/suspend', [SuperAdmin\TenantController::class, 'suspend']);
                Route::post('tenants/{tenant}/activate', [SuperAdmin\TenantController::class, 'activate']);
                Route::post('tenants/{tenant}/deactivate', [SuperAdmin\TenantController::class, 'deactivate']);
                Route::post('tenants/{tenant}/extend', [SuperAdmin\TenantController::class, 'extend']);
                Route::put('tenants/{tenant}/expiry', [SuperAdmin\TenantController::class, 'setExpiry']);
                Route::put('tenants/{tenant}/limits', [SuperAdmin\TenantController::class, 'setLimits']);
                Route::get('tenants/{tenant}/subscription', [SuperAdmin\TenantController::class, 'subscription']);
                Route::post('tenants/{tenant}/owner/reset-password', [SuperAdmin\TenantController::class, 'resetOwnerPassword']);
            });
            Route::middleware('perm:platform.payments')->group(function (): void {
                Route::post('tenants/{tenant}/payments', [SuperAdmin\TenantController::class, 'recordPayment']);
                Route::get('payments', SuperAdmin\PaymentController::class);
            });
            Route::get('audit-logs', SuperAdmin\AuditLogController::class)->middleware('perm:platform.audit');
            Route::get('settings', [SuperAdmin\SettingsController::class, 'show'])->middleware('perm:platform.settings');
            Route::put('settings', [SuperAdmin\SettingsController::class, 'update'])->middleware('perm:platform.settings');
        });
    });
});
