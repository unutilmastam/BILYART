<?php

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;

final class SetLimitsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'branchLimit' => ['required', 'integer', 'min:1', 'max:1000'],
            'tableLimit' => ['present', 'nullable', 'integer', 'min:1', 'max:100000'],
            'deviceLimit' => ['present', 'nullable', 'integer', 'min:1', 'max:100000'],
            'userLimit' => ['present', 'nullable', 'integer', 'min:1', 'max:10000'],
        ];
    }
}
