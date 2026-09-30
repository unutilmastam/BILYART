<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class TenantSettingsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'privacyNotice' => ['sometimes', 'string', 'min:10', 'max:2000'],
            'photoRetentionDays' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'warningText' => ['sometimes', 'string', 'max:200'],
            'warnBeforeMinutes' => ['sometimes', 'integer', 'min:1', 'max:30'],
            'locale' => ['sometimes', Rule::in(['uz', 'ru'])],
            'operatorsCanViewPhotos' => ['sometimes', 'boolean'],
        ];
    }
}
