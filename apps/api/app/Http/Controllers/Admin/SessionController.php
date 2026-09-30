<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Branches\Services\BranchAccess;
use App\Domain\Sessions\Enums\PaymentStatus;
use App\Domain\Sessions\Enums\SessionStatus;
use App\Domain\Sessions\Models\GameSession;
use App\Domain\Sessions\Services\SessionService;
use App\Http\Controllers\Controller;
use App\Http\Resources\SessionResource;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use App\Support\Http\Paginates;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class SessionController extends Controller
{
    use Paginates;

    public function __construct(
        private readonly SessionService $sessions,
        private readonly BranchAccess $access,
    ) {}

    public function index(Request $request): array
    {
        $request->validate([
            'branchId' => ['nullable', 'string', 'size:26'],
            'tableId' => ['nullable', 'string', 'size:26'],
            'status' => ['nullable', Rule::enum(SessionStatus::class)],
            'payment' => ['nullable', Rule::enum(PaymentStatus::class)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);
        $query = $this->access->scope(GameSession::query(), $request->user())
            ->with(['branch', 'table', 'device', 'photo'])
            ->orderByDesc('id');
        if ($v = $request->query('branchId')) {
            $query->whereHas('branch', fn ($q) => $q->where('public_id', $v));
        }
        if ($v = $request->query('tableId')) {
            $query->whereHas('table', fn ($q) => $q->where('public_id', $v));
        }
        if ($v = $request->query('status')) {
            $query->where('status', $v);
        }
        if ($v = $request->query('payment')) {
            $query->where('payment_status', $v);
        }
        if ($v = $request->query('from')) {
            $query->where('created_at', '>=', $v);
        }
        if ($v = $request->query('to')) {
            $query->where('created_at', '<=', $v);
        }

        return $this->paginate($query, $request, SessionResource::class);
    }

    public function show(Request $request, GameSession $session): SessionResource
    {
        $this->authorizeBranch($request, $session);

        return new SessionResource($session->load(['branch', 'table', 'device', 'photo', 'events']));
    }

    public function stop(Request $request, GameSession $session): SessionResource
    {
        $this->authorizeBranch($request, $session);

        return new SessionResource($this->sessions->stop($request->user(), $session)->load(['branch', 'table', 'device', 'photo', 'events']));
    }

    public function payment(Request $request, GameSession $session): SessionResource
    {
        $this->authorizeBranch($request, $session);
        $data = $request->validate(['status' => ['required', Rule::enum(PaymentStatus::class)]]);

        return new SessionResource($this->sessions->markPayment($request->user(), $session, PaymentStatus::from($data['status']))->load(['branch', 'table', 'device', 'photo']));
    }

    private function authorizeBranch(Request $request, GameSession $session): void
    {
        if (! $this->access->canAccess($request->user(), $session->branch_id)) {
            throw ApiException::of(ErrorCode::NOT_FOUND);
        }
    }
}
