<?php

namespace App\Domain\Cash\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Branches\Models\Branch;
use App\Domain\Cash\Enums\CashNoteStatus;
use App\Domain\Cash\Models\CashCollection;
use App\Domain\Cash\Models\CashNote;
use App\Domain\Devices\Models\Device;
use App\Domain\Notifications\Enums\Severity;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Users\Models\User;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use Illuminate\Support\Facades\DB;

/** Staff side of the cash box: emptying it (collection) and resolving bills no session could take. */
final class CashLedger
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
    ) {}

    /** Every bill in the box since the last collection is assigned to this collection (expected vs counted). */
    public function collect(User $user, Device $cash, int $counted, ?string $comment): CashCollection
    {
        return DB::transaction(function () use ($user, $cash, $counted, $comment): CashCollection {
            Device::query()->whereKey($cash->id)->lockForUpdate()->firstOrFail();
            $open = CashNote::query()->where('device_id', $cash->id)->whereNull('collection_id');
            $expected = (int) (clone $open)->sum('nominal');
            $count = (clone $open)->count();

            $collection = new CashCollection;
            $collection->forceFill([
                'branch_id' => $cash->branch_id,
                'device_id' => $cash->id,
                'expected_amount' => $expected,
                'counted_amount' => $counted,
                'notes_count' => $count,
                'collected_by' => $user->id,
                'comment' => $comment,
            ])->save();
            (clone $open)->update(['collection_id' => $collection->id]);

            $this->audit->log('cash.collected', $collection, ['expected' => $expected, 'counted' => $counted, 'notes' => $count, 'device' => $cash->device_code]);
            if ($counted !== $expected) {
                $this->notifications->notify($cash->tenant_id, 'cash_mismatch', 'cash_mismatch:'.$collection->id, [
                    'text' => __('notifications.cash_mismatch', [
                        'branch' => Branch::query()->find($cash->branch_id)?->name ?? '—',
                        'expected' => number_format($expected, 0, '.', ' '),
                        'counted' => number_format($counted, 0, '.', ' '),
                        'user' => $user->name,
                    ]),
                    'branchId' => $cash->branch_id,
                ], Severity::WARNING);
            }

            return $collection;
        });
    }

    public function resolve(User $user, CashNote $note, string $comment): CashNote
    {
        return DB::transaction(function () use ($user, $note, $comment): CashNote {
            /** @var CashNote $locked */
            $locked = CashNote::query()->lockForUpdate()->findOrFail($note->id);
            if ($locked->status !== CashNoteStatus::UNASSIGNED) {
                throw ApiException::of(ErrorCode::INVALID_STATE_TRANSITION);
            }
            $locked->forceFill([
                'status' => CashNoteStatus::RESOLVED,
                'resolved_by' => $user->id,
                'resolved_at' => now(),
                'resolve_comment' => $comment,
            ])->save();
            $this->audit->log('cash.note_resolved', $locked, ['nominal' => $locked->nominal, 'comment' => $comment]);

            return $locked;
        });
    }
}
