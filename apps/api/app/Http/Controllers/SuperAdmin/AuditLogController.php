<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Domain\Audit\Models\AuditLog;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Support\Http\Paginates;
use Illuminate\Http\Request;

final class AuditLogController extends Controller
{
    use Paginates;

    public function __invoke(Request $request): array
    {
        $request->validate([
            'tenantId' => ['nullable', 'string', 'size:26'],
            'action' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $query = AuditLog::query()
            ->leftJoin('tenants', 'tenants.id', '=', 'audit_logs.tenant_id')
            ->leftJoin('users', fn ($j) => $j->on('users.id', '=', 'audit_logs.actor_id')->where('audit_logs.actor_type', '=', 'USER'))
            ->select('audit_logs.*', 'tenants.name as tenant_name', 'users.name as actor_name')
            ->orderByDesc('audit_logs.id');

        if ($tenantId = $request->query('tenantId')) {
            $query->where('tenants.public_id', $tenantId);
        }
        if ($action = $request->query('action')) {
            $query->where('audit_logs.action', 'like', $action.'%');
        }
        if ($from = $request->query('from')) {
            $query->where('audit_logs.created_at', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->where('audit_logs.created_at', '<=', $to);
        }

        return $this->paginate($query, $request, AuditLogResource::class);
    }
}
