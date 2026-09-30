<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Auth\Permissions;
use App\Domain\Auth\Services\LoginService;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Users\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

final class AuthController extends Controller
{
    /** GET /api/auth/csrf — sets the XSRF-TOKEN cookie for the SPA. */
    public function csrf(): Response
    {
        return response()->noContent();
    }

    public function login(LoginRequest $request, LoginService $service): JsonResponse
    {
        $user = $service->attempt($request->string('login'), (string) $request->input('password'));

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json($this->profile($user));
    }

    public function logout(Request $request, AuditLogger $audit): Response
    {
        $audit->log('auth.logout', $request->user());
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->profile($request->user()));
    }

    public function changePassword(ChangePasswordRequest $request, AuditLogger $audit): Response
    {
        /** @var User $user */
        $user = $request->user();
        if (! Hash::check((string) $request->input('currentPassword'), $user->password)) {
            throw new ApiException(ErrorCode::VALIDATION_FAILED, [], ['fields' => ['currentPassword' => [__('auth.password')]]]);
        }
        $user->forceFill(['password' => (string) $request->input('password')])->save();
        Auth::guard('web')->logoutOtherDevices((string) $request->input('password'));
        $audit->log('user.password_changed', $user);

        return response()->noContent();
    }

    private function profile(User $user): array
    {
        $tenant = $user->tenant_id !== null ? Tenant::query()->withoutGlobalScopes()->find($user->tenant_id) : null;

        return [
            'user' => new UserResource($user),
            'permissions' => Permissions::forRole($user->role),
            'tenant' => $tenant === null ? null : [
                'id' => $tenant->public_id,
                'name' => $tenant->name,
                'subscription' => [
                    'status' => $tenant->subscriptionStatus()->value,
                    'expiresAt' => $tenant->subscription_expires_at?->toIso8601ZuluString(),
                    'daysLeft' => $tenant->daysLeft(),
                ],
            ],
        ];
    }
}
