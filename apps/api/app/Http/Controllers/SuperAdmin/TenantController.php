<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Subscriptions\Enums\PaymentMethod;
use App\Domain\Subscriptions\Models\Subscription;
use App\Domain\Subscriptions\Models\SubscriptionEvent;
use App\Domain\Subscriptions\Models\SubscriptionPayment;
use App\Domain\Subscriptions\Services\SubscriptionService;
use App\Domain\Tenancy\Enums\SubscriptionStatus;
use App\Domain\Tenancy\Enums\TenantStatusFlag;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\TenantService;
use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\CreateTenantRequest;
use App\Http\Requests\SuperAdmin\ExtendRequest;
use App\Http\Requests\SuperAdmin\RecordPaymentRequest;
use App\Http\Requests\SuperAdmin\SetExpiryRequest;
use App\Http\Requests\SuperAdmin\SetLimitsRequest;
use App\Http\Requests\SuperAdmin\StatusChangeRequest;
use App\Http\Requests\SuperAdmin\UpdateTenantRequest;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\TenantResource;
use App\Support\Http\Paginates;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Super Admin → Clients. Runs in SYSTEM context (set by ResolveUserTenant for SUPER_ADMIN). */
final class TenantController extends Controller
{
    use Paginates;

    public function __construct(
        private readonly TenantService $tenants,
        private readonly SubscriptionService $subscriptions,
    ) {}

    public function index(Request $request): array
    {
        $request->validate([
            'status' => ['nullable', Rule::enum(SubscriptionStatus::class)],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $query = $this->withUsage(Tenant::query())->orderBy('name');
        if ($status = $request->query('status')) {
            $query->whereSubscriptionStatus(SubscriptionStatus::from($status));
        }
        if ($q = trim((string) $request->query('q'))) {
            $query->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('contact_phone', 'like', "%{$q}%"));
        }

        return $this->paginate($query, $request, TenantResource::class);
    }

    public function store(CreateTenantRequest $request): JsonResponse
    {
        $payment = $request->input('payment') ? [
            'amount' => (int) $request->input('payment.amount'),
            'method' => PaymentMethod::from($request->input('payment.method')),
            'days' => (int) $request->input('payment.days'),
            'note' => $request->input('payment.note'),
        ] : null;

        $tenant = $this->tenants->create([
            'name' => $request->input('name'),
            'contact_name' => $request->input('contactName'),
            'contact_phone' => $request->input('contactPhone'),
            'timezone' => $request->input('timezone', 'Asia/Tashkent'),
            'branch_limit' => (int) $request->input('branchLimit'),
            'table_limit' => $request->input('tableLimit'),
            'device_limit' => $request->input('deviceLimit'),
            'user_limit' => $request->input('userLimit'),
        ], $request->input('owner'), $payment, $request->user());

        return (new TenantResource($this->reload($tenant)))->response()->setStatusCode(201);
    }

    public function show(Tenant $tenant): TenantResource
    {
        return new TenantResource($this->reload($tenant));
    }

    public function update(UpdateTenantRequest $request, Tenant $tenant): TenantResource
    {
        $map = ['name' => 'name', 'contactName' => 'contact_name', 'contactPhone' => 'contact_phone', 'timezone' => 'timezone'];
        $changes = [];
        foreach ($map as $in => $column) {
            if ($request->has($in)) {
                $changes[$column] = $request->input($in);
            }
        }
        $tenant->fill($changes)->save();
        app(AuditLogger::class)->log('tenant.updated', $tenant, ['fields' => array_keys($changes)], ['tenant_id' => $tenant->id]);

        return new TenantResource($this->reload($tenant));
    }

    public function suspend(StatusChangeRequest $request, Tenant $tenant): TenantResource
    {
        return $this->status($request, $tenant, TenantStatusFlag::SUSPENDED);
    }

    public function activate(StatusChangeRequest $request, Tenant $tenant): TenantResource
    {
        return $this->status($request, $tenant, TenantStatusFlag::ACTIVE);
    }

    public function deactivate(StatusChangeRequest $request, Tenant $tenant): TenantResource
    {
        return $this->status($request, $tenant, TenantStatusFlag::DEACTIVATED);
    }

    public function recordPayment(RecordPaymentRequest $request, Tenant $tenant): JsonResponse
    {
        $this->subscriptions->recordPayment(
            $tenant,
            (int) $request->input('amount'),
            PaymentMethod::from($request->input('method')),
            (int) $request->input('days'),
            $request->user(),
            $request->filled('paidAt') ? CarbonImmutable::parse($request->input('paidAt'))->utc() : null,
            $request->input('note'),
        );

        return (new TenantResource($this->reload($tenant)))->response()->setStatusCode(201);
    }

    public function extend(ExtendRequest $request, Tenant $tenant): TenantResource
    {
        $this->subscriptions->extend($tenant, (int) $request->input('days'), $request->user(), (string) $request->input('reason'));

        return new TenantResource($this->reload($tenant));
    }

    public function setExpiry(SetExpiryRequest $request, Tenant $tenant): TenantResource
    {
        $this->subscriptions->setExpiry($tenant, CarbonImmutable::parse($request->input('expiresAt'))->utc(), $request->user(), (string) $request->input('reason'));

        return new TenantResource($this->reload($tenant));
    }

    public function setLimits(SetLimitsRequest $request, Tenant $tenant): TenantResource
    {
        $this->subscriptions->setLimits($tenant, [
            'branch_limit' => (int) $request->input('branchLimit'),
            'table_limit' => $request->input('tableLimit') === null ? null : (int) $request->input('tableLimit'),
            'device_limit' => $request->input('deviceLimit') === null ? null : (int) $request->input('deviceLimit'),
            'user_limit' => $request->input('userLimit') === null ? null : (int) $request->input('userLimit'),
        ], $request->user());

        return new TenantResource($this->reload($tenant));
    }

    public function subscription(Tenant $tenant): array
    {
        return [
            'tenant' => (new TenantResource($this->reload($tenant)))->resolve(),
            'periods' => Subscription::query()->where('tenant_id', $tenant->id)->orderByDesc('id')->get()->map(fn (Subscription $s) => [
                'id' => $s->public_id,
                'startsAt' => $s->starts_at->toIso8601ZuluString(),
                'expiresAt' => $s->expires_at->toIso8601ZuluString(),
                'days' => $s->days,
                'source' => $s->source->value,
                'reason' => $s->reason,
                'createdAt' => $s->created_at?->toIso8601ZuluString(),
            ]),
            'payments' => PaymentResource::collection(SubscriptionPayment::query()->where('tenant_id', $tenant->id)->orderByDesc('paid_at')->get())->resolve(),
            'events' => SubscriptionEvent::query()->where('tenant_id', $tenant->id)->orderByDesc('id')->limit(200)->get()->map(fn (SubscriptionEvent $e) => [
                'type' => $e->type->value,
                'oldValue' => $e->old_value,
                'newValue' => $e->new_value,
                'createdAt' => $e->created_at->toIso8601ZuluString(),
            ]),
        ];
    }

    public function resetOwnerPassword(Request $request, Tenant $tenant): JsonResponse
    {
        return response()->json($this->tenants->resetOwnerPassword($tenant, $request->user()));
    }

    private function status(StatusChangeRequest $request, Tenant $tenant, TenantStatusFlag $flag): TenantResource
    {
        $this->subscriptions->setStatus($tenant, $flag, $request->user(), $request->input('reason'));

        return new TenantResource($this->reload($tenant));
    }

    private function reload(Tenant $tenant): Tenant
    {
        return $this->withUsage(Tenant::query())->findOrFail($tenant->id);
    }

    private function withUsage($query)
    {
        return $query->withCount([
            'branches',
            'users',
            'tables',
            'devices' => fn ($q) => $q->where('status', 'PAIRED'),
        ]);
    }
}
