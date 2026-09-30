<?php

namespace App\Http\Requests\SuperAdmin;

use App\Domain\Subscriptions\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RecordPaymentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer', 'min:0', 'max:1000000000000'],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'days' => ['required', 'integer', 'min:1', 'max:3660'],
            'paidAt' => ['nullable', 'date', 'before_or_equal:now'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
