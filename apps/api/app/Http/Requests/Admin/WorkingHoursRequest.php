<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class WorkingHoursRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'days' => ['required', 'array', 'size:7'],
            'days.*.weekday' => ['required', 'integer', 'between:1,7', 'distinct'],
            'days.*.isClosed' => ['required', 'boolean'],
            'days.*.opensAt' => ['required_if:days.*.isClosed,false', 'nullable', 'date_format:H:i'],
            'days.*.closesAt' => ['required_if:days.*.isClosed,false', 'nullable', 'date_format:H:i'],
        ];
    }
}
