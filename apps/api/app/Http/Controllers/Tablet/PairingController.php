<?php

namespace App\Http\Controllers\Tablet;

use App\Domain\Tablets\Services\TabletRegistry;
use App\Http\Controllers\Controller;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Public, rate-limited start of tablet pairing (protocol: tablet.register / tablet.pairing-status). */
final class PairingController extends Controller
{
    public function __construct(private readonly TabletRegistry $registry) {}

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'appVersion' => ['required', 'string', 'max:32', 'regex:/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/'],
            'deviceModel' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json($this->registry->register($data['appVersion'], $data['deviceModel'] ?? null, (string) $request->ip()), 201);
    }

    public function status(Request $request): array
    {
        $header = (string) $request->header('Authorization', '');
        if (! preg_match('/^PollToken ([A-Za-z0-9_-]{32,128})$/', $header, $m)) {
            throw ApiException::of(ErrorCode::UNAUTHENTICATED);
        }

        return $this->registry->pairingStatus($m[1]);
    }
}
