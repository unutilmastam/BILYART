<?php

namespace App\Http\Requests\SuperAdmin;

use App\Domain\Subscriptions\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class CreateTenantRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'contactName' => ['nullable', 'string', 'max:150'],
            'contactPhone' => ['nullable', 'string', 'max:32'],
            'timezone' => ['nullable', 'timezone:all'],
            'branchLimit' => ['required', 'integer', 'min:1', 'max:1000'],
            'tableLimit' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'deviceLimit' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'userLimit' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'owner.name' => ['required', 'string', 'max:150'],
            'owner.login' => ['required', 'string', 'min:3', 'max:64', 'regex:/^[A-Za-z0-9._+-]+$/', Rule::unique('users', 'login')],
            'owner.password' => ['required', 'string', 'max:200', Password::min(10)->letters()->numbers()],
            'payment' => ['nullable', 'array'],
            'payment.amount' => ['required_with:payment', 'integer', 'min:0', 'max:1000000000000'],
            'payment.method' => ['required_with:payment', Rule::enum(PaymentMethod::class)],
            'payment.days' => ['required_with:payment', 'integer', 'min:1', 'max:3660'],
            'payment.note' => ['nullable', 'string', 'max:500'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('owner.login'))) {
            $this->merge(['owner' => array_merge((array) $this->input('owner'), ['login' => strtolower(trim($this->input('owner.login')))])]);
        }
    }
}
