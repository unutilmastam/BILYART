<?php

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;

final class UpdatePlatformSettingsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'supportContact' => ['sometimes', 'nullable', 'string', 'max:200'],
            'paymentInstructions' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'defaultBranchLimit' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'reminderDays' => ['sometimes', 'array', 'min:1', 'max:10'],
            'reminderDays.*' => ['integer', 'min:0', 'max:60', 'distinct'],
            'pricePerBranch' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
        ];
    }
}
