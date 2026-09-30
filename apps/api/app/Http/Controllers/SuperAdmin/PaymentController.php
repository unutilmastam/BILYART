<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Domain\Subscriptions\Models\SubscriptionPayment;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Support\Http\Paginates;
use Illuminate\Http\Request;

final class PaymentController extends Controller
{
    use Paginates;

    public function __invoke(Request $request): array
    {
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $query = SubscriptionPayment::query()->with('tenant')->orderByDesc('paid_at')->orderByDesc('id');
        if ($from = $request->query('from')) {
            $query->where('paid_at', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->where('paid_at', '<=', $to);
        }

        $result = $this->paginate($query, $request, PaymentResource::class);
        $result['meta']['sum'] = (int) (clone $query)->reorder()->sum('amount');

        return $result;
    }
}
