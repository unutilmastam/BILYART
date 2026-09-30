<?php

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;

final class ExtendRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'days' => ['required', 'integer', 'min:1', 'max:3660'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
