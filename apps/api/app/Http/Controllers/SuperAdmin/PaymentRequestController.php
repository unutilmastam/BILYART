<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Domain\Subscriptions\Enums\PaymentMethod;
use App\Domain\Subscriptions\Enums\PaymentRequestStatus;
use App\Domain\Subscriptions\Models\SubscriptionPaymentRequest;
use App\Domain\Subscriptions\Services\PaymentRequestService;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentRequestResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/** Super Admin → client payment requests: check the receipt against the account, then approve or reject. */
final class PaymentRequestController extends Controller
{
    public function __construct(private readonly PaymentRequestService $requests) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $status = $request->validate(['status' => ['nullable', Rule::enum(PaymentRequestStatus::class)]])['status'] ?? null;

        return PaymentRequestResource::collection(
            SubscriptionPaymentRequest::query()->with('tenant')
                ->when($status, fn ($q) => $q->where('status', $status))
                ->orderByRaw("CASE WHEN status = 'PENDING' THEN 0 ELSE 1 END")->orderByDesc('id')
                ->limit(100)->get()
        );
    }

    public function approve(Request $request, SubscriptionPaymentRequest $paymentRequest): PaymentRequestResource
    {
        $data = $request->validate([
            'amount' => ['nullable', 'integer', 'min:1', 'max:1000000000'],
            'method' => ['nullable', Rule::enum(PaymentMethod::class)],
        ]);
        $method = isset($data['method']) ? PaymentMethod::from($data['method']) : PaymentMethod::CARD_TRANSFER;

        return new PaymentRequestResource($this->requests->approve($paymentRequest, $request->user(), $data['amount'] ?? null, $method)->load('tenant'));
    }

    public function reject(Request $request, SubscriptionPaymentRequest $paymentRequest): PaymentRequestResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return new PaymentRequestResource($this->requests->reject($paymentRequest, $request->user(), $data['reason'])->load('tenant'));
    }

    public function receipt(SubscriptionPaymentRequest $paymentRequest): Response
    {
        return response($this->requests->receipt($paymentRequest), 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
