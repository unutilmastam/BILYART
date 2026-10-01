<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Subscriptions\Models\SubscriptionPaymentRequest;
use App\Domain\Subscriptions\Services\PaymentRequestService;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentRequestResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Client owner → subscription payment requests (billing.manage). Reachable even when the
 * subscription has expired — that is exactly when the owner needs to pay.
 */
final class PaymentRequestController extends Controller
{
    public function __construct(private readonly PaymentRequestService $requests) {}

    public function index(): AnonymousResourceCollection
    {
        return PaymentRequestResource::collection(SubscriptionPaymentRequest::query()->orderByDesc('id')->limit(20)->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'months' => ['required', 'integer'],
            'receipt' => ['required', 'file', 'max:8192'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $created = $this->requests->create($request->user(), (int) $data['months'], (string) file_get_contents($request->file('receipt')->getRealPath()), $data['note'] ?? null);

        return (new PaymentRequestResource($created))->response()->setStatusCode(201);
    }

    public function cancel(SubscriptionPaymentRequest $paymentRequest): PaymentRequestResource
    {
        return new PaymentRequestResource($this->requests->cancel($paymentRequest));
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
