<?php

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateTenantRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'contactName' => ['sometimes', 'nullable', 'string', 'max:150'],
            'contactPhone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'timezone' => ['sometimes', 'required', 'timezone:all'],
        ];
    }
}
