<?php

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;

final class SetExpiryRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'expiresAt' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
