<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\Services\TwoFactorService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Own account only: /api/me/2fa/* (SECURITY.md §1a). */
final class TwoFactorController extends Controller
{
    public function __construct(private readonly TwoFactorService $twoFactor) {}

    public function setup(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string', 'max:200']]);

        return response()->json($this->twoFactor->begin($request->user(), $data['password']));
    }

    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:16']]);

        return response()->json(['recoveryCodes' => $this->twoFactor->confirm($request->user(), $data['code'])]);
    }

    public function disable(Request $request): Response
    {
        $data = $request->validate(['password' => ['required', 'string', 'max:200'], 'code' => ['required', 'string', 'max:16']]);
        $this->twoFactor->disable($request->user(), $data['password'], $data['code']);

        return response()->noContent();
    }
}
