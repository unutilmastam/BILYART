<?php

namespace App\Http\Controllers;

use App\Domain\Health\HealthService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /health[/db|/storage|/messaging]. Public: overall status only. Details: HEALTH_TOKEN (Bearer). */
final class HealthController extends Controller
{
    public function __invoke(Request $request, HealthService $health, TenantContext $context, ?string $check = null): JsonResponse
    {
        $token = (string) config('app.health_token');
        $detailed = $token !== '' && hash_equals($token, (string) $request->bearerToken());

        $result = $context->runAsSystem(fn () => $check === null ? $health->all() : ['status' => $health->{$check}()['status'], 'checks' => [$check => $health->{$check}()]]);
        $code = $result['status'] === 'fail' ? 503 : 200;

        return response()->json($detailed ? $result : ['status' => $result['status']], $code);
    }
}
