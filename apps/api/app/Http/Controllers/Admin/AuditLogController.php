<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\Models\AuditLog;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Support\Http\Paginates;
use Illuminate\Http\Request;

/** The tenant's own audit trail (tenant-scoped model). */
final class AuditLogController extends Controller
{
    use Paginates;

    public function __invoke(Request $request): array
    {
        $request->validate(['action' => ['nullable', 'string', 'max:64']]);
        $query = AuditLog::query()
            ->leftJoin('users', fn ($j) => $j->on('users.id', '=', 'audit_logs.actor_id')->where('audit_logs.actor_type', '=', 'USER'))
            ->select('audit_logs.*', 'users.name as actor_name')
            ->orderByDesc('audit_logs.id');
        if ($action = $request->query('action')) {
            $query->where('audit_logs.action', 'like', $action.'%');
        }

        return $this->paginate($query, $request, AuditLogResource::class);
    }
}
