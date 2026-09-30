<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class ChangePasswordRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'currentPassword' => ['required', 'string', 'max:200'],
            'password' => ['required', 'string', 'max:200', 'different:currentPassword', Password::min(10)->letters()->numbers()],
        ];
    }
}
