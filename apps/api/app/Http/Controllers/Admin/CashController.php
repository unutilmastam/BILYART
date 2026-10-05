<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Branches\Enums\PaymentMode;
use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Services\BranchAccess;
use App\Domain\Cash\Enums\CashNoteStatus;
use App\Domain\Cash\Models\CashCollection;
use App\Domain\Cash\Models\CashNote;
use App\Domain\Cash\Services\CashLedger;
use App\Domain\Devices\Enums\DeviceKind;
use App\Domain\Devices\Enums\DeviceStatus;
use App\Domain\Devices\Models\Device;
use App\Domain\Sessions\Models\GameSession;
use App\Domain\Tables\Models\BilliardTable;
use App\Domain\Users\Models\User;
use App\Http\Controllers\Controller;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Client Admin → Kassa: bill acceptor boxes, bills, unassigned money, collections (owner request 2026-10-05). */
final class CashController extends Controller
{
    public function __construct(
        private readonly BranchAccess $access,
        private readonly CashLedger $ledger,
    ) {}

    public function index(Request $request): array
    {
        $user = $request->user();
        $branches = $this->access->scope(Branch::query(), $user, 'id')->where('is_active', true)->orderBy('name')->get();
        $devices = Device::query()->whereIn('branch_id', $branches->pluck('id'))->where('kind', DeviceKind::CASH->value)
            ->where('status', DeviceStatus::PAIRED->value)->get()->keyBy('branch_id');

        $boxes = $branches->filter(fn (Branch $b) => $b->payment_mode === PaymentMode::BILL_ACCEPTOR || $devices->has($b->id))->map(function (Branch $b) use ($devices) {
            /** @var Device|null $device */
            $device = $devices->get($b->id);
            $dayStart = CarbonImmutable::now($b->timezone)->startOfDay()->utc();
            $notes = fn () => CashNote::query()->where('branch_id', $b->id);

            return [
                'branch' => ['id' => $b->public_id, 'name' => $b->name, 'paymentMode' => $b->payment_mode->value],
                'device' => $device ? [
                    'id' => $device->public_id,
                    'code' => $device->device_code,
                    'online' => $device->isOnline(),
                    'lastSeenAt' => $device->last_seen_at?->toIso8601ZuluString(),
                    'accepting' => (bool) ($device->last_state['accepting'] ?? false),
                    'queued' => (int) ($device->last_state['queued'] ?? 0),
                ] : null,
                'uncollected' => $this->sum($device ? CashNote::query()->where('device_id', $device->id)->whereNull('collection_id') : null),
                'today' => $this->sum($notes()->where('received_at', '>=', $dayStart)),
                'unassigned' => $this->sum($notes()->where('status', CashNoteStatus::UNASSIGNED->value)),
            ];
        })->values();

        $branchIds = $branches->pluck('id');
        $names = $branches->pluck('name', 'id');
        $notes = CashNote::query()->whereIn('branch_id', $branchIds)->orderByDesc('id')->limit(50)->get();
        $sessions = GameSession::query()->whereIn('id', $notes->pluck('session_id')->filter())->get(['id', 'public_id', 'table_id'])->keyBy('id');
        $tables = BilliardTable::query()->whereIn('id', $sessions->pluck('table_id'))->pluck('name', 'id');
        $collections = CashCollection::query()->whereIn('branch_id', $branchIds)->orderByDesc('id')->limit(20)->get();
        $people = User::query()->whereIn('id', $collections->pluck('collected_by'))->pluck('name', 'id');
        $codes = Device::query()->whereIn('id', $collections->pluck('device_id'))->pluck('device_code', 'id');

        return [
            'boxes' => $boxes,
            'notes' => $notes->map(fn (CashNote $n) => [
                'id' => $n->public_id,
                'nominal' => $n->nominal,
                'status' => $n->status->value,
                'receivedAt' => $n->received_at->toIso8601ZuluString(),
                'branch' => $names[$n->branch_id] ?? null,
                'table' => ($s = $sessions->get($n->session_id)) ? ($tables[$s->table_id] ?? null) : null,
                'sessionId' => $s?->public_id,
                'resolveComment' => $n->resolve_comment,
            ])->values(),
            'collections' => $collections->map(fn (CashCollection $c) => [
                'id' => $c->public_id,
                'branch' => $names[$c->branch_id] ?? null,
                'deviceCode' => $codes[$c->device_id] ?? null,
                'expected' => $c->expected_amount,
                'counted' => $c->counted_amount,
                'notesCount' => $c->notes_count,
                'collectedBy' => $people[$c->collected_by] ?? null,
                'comment' => $c->comment,
                'createdAt' => $c->created_at->toIso8601ZuluString(),
            ])->values(),
        ];
    }

    public function collect(Request $request): JsonResponse
    {
        $data = $request->validate([
            'deviceId' => ['required', 'string', 'size:26'],
            'countedAmount' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'comment' => ['nullable', 'string', 'max:300'],
        ]);
        $device = Device::query()->where('public_id', $data['deviceId'])->where('kind', DeviceKind::CASH->value)->where('status', DeviceStatus::PAIRED->value)->first();
        if ($device === null || ! $this->access->canAccess($request->user(), (int) $device->branch_id)) {
            throw ApiException::of(ErrorCode::NOT_FOUND);
        }
        $c = $this->ledger->collect($request->user(), $device, (int) $data['countedAmount'], $data['comment'] ?? null);

        return response()->json(['data' => [
            'id' => $c->public_id, 'expected' => $c->expected_amount, 'counted' => $c->counted_amount, 'notesCount' => $c->notes_count,
        ]], 201);
    }

    public function resolve(Request $request, CashNote $cashNote): array
    {
        $data = $request->validate(['comment' => ['required', 'string', 'min:3', 'max:300']]);
        if (! $this->access->canAccess($request->user(), (int) $cashNote->branch_id)) {
            throw ApiException::of(ErrorCode::NOT_FOUND);
        }
        $note = $this->ledger->resolve($request->user(), $cashNote, $data['comment']);

        return ['data' => ['id' => $note->public_id, 'status' => $note->status->value, 'resolveComment' => $note->resolve_comment]];
    }

    /** @return array{amount: int, count: int} */
    private function sum($query): array
    {
        if ($query === null) {
            return ['amount' => 0, 'count' => 0];
        }

        return ['amount' => (int) (clone $query)->sum('nominal'), 'count' => (clone $query)->count()];
    }
}
