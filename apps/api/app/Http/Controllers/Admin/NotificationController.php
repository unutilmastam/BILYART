<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Notifications\Models\Notification;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

/** In-app notifications of the current tenant (tenant scope) — or of the platform for the Super Admin (tenant_id NULL). */
final class NotificationController extends Controller
{
    public function index(): array
    {
        $query = Notification::query()->when(app(TenantContext::class)->isSystem(), fn ($q) => $q->whereNull('tenant_id'));

        return [
            'unread' => (clone $query)->whereNull('read_at')->count(),
            'data' => $query->orderByDesc('id')->limit(50)->get()->map(fn (Notification $n) => [
                'id' => $n->public_id,
                'type' => $n->type,
                'severity' => $n->severity->value,
                'text' => $n->payload['text'] ?? '',
                'createdAt' => $n->created_at->toIso8601ZuluString(),
                'readAt' => $n->read_at?->toIso8601ZuluString(),
            ]),
        ];
    }

    public function read(Notification $notification): Response
    {
        $this->assertVisible($notification);
        $notification->forceFill(['read_at' => $notification->read_at ?? now()])->save();

        return response()->noContent();
    }

    public function readAll(): Response
    {
        Notification::query()->when(app(TenantContext::class)->isSystem(), fn ($q) => $q->whereNull('tenant_id'))
            ->whereNull('read_at')->update(['read_at' => now()]);

        return response()->noContent();
    }

    private function assertVisible(Notification $notification): void
    {
        if (app(TenantContext::class)->isSystem() && $notification->tenant_id !== null) {
            abort(404); // the Super Admin reads only platform notifications here
        }
    }
}
