<?php

namespace App\Http\Controllers\Tablet;

use App\Domain\Sessions\Models\GameSession;
use App\Domain\Sessions\Services\SessionService;
use App\Domain\Tables\Models\BilliardTable;
use App\Domain\Tablets\Models\Tablet;
use App\Http\Controllers\Controller;
use App\Http\Resources\Tablet\TabletSessionResource;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Customer session flow (ARCHITECTURE §5). All state changes go through SessionService. */
final class SessionController extends Controller
{
    public function __construct(private readonly SessionService $sessions) {}

    public function prepare(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tableId' => ['required', 'string', 'size:26'],
            'durationMinutes' => ['required', 'integer', 'min:1', 'max:720'],
        ]);
        $table = BilliardTable::query()->where('public_id', $data['tableId'])->first()
            ?? throw ApiException::of(ErrorCode::NOT_FOUND);

        $session = $this->sessions->prepare($this->tablet($request), $table, (int) $data['durationMinutes']);

        return response()->json(TabletSessionResource::make($session), 201);
    }

    public function start(Request $request, GameSession $session): array
    {
        return TabletSessionResource::make($this->sessions->start($this->tablet($request), $session));
    }

    public function cancel(Request $request, GameSession $session): array
    {
        return TabletSessionResource::make($this->sessions->cancel($this->tablet($request), $session));
    }

    public function show(Request $request, GameSession $session): array
    {
        if ($session->tablet_id !== $this->tablet($request)->id && $session->branch_id !== $this->tablet($request)->branch_id) {
            throw ApiException::of(ErrorCode::NOT_FOUND);
        }

        return TabletSessionResource::make($session);
    }

    private function tablet(Request $request): Tablet
    {
        return $request->attributes->get('tablet');
    }
}
