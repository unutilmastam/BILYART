<?php

namespace App\Http\Requests\Admin;

use App\Domain\Branches\Models\Branch;
use Illuminate\Foundation\Http\FormRequest;

final class PricingPlanRequest extends FormRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:150'],
            'branchId' => ['sometimes', 'nullable', 'string', 'size:26'],
            'pricePerHour' => [$required, 'integer', 'min:0', 'max:100000000'],
            'roundingStep' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'allowedDurations' => [$required, 'array', 'min:1', 'max:20'],
            'allowedDurations.*' => ['integer', 'min:1', 'max:720', 'distinct'],
            'isActive' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, mixed>|null null when branchId does not resolve inside the tenant */
    public function columns(): ?array
    {
        $out = [];
        foreach (['name' => 'name', 'pricePerHour' => 'price_per_hour', 'roundingStep' => 'rounding_step', 'allowedDurations' => 'allowed_durations'] as $in => $col) {
            if ($this->has($in)) {
                $out[$col] = $in === 'allowedDurations' ? array_map('intval', $this->input($in)) : $this->input($in);
            }
        }
        if ($this->has('isActive')) {
            $out['is_active'] = $this->boolean('isActive');
        }
        if ($this->has('branchId')) {
            if (! $this->filled('branchId')) {
                $out['branch_id'] = null;
            } else {
                $id = Branch::query()->where('public_id', $this->input('branchId'))->value('id');
                if ($id === null) {
                    return null;
                }
                $out['branch_id'] = $id;
            }
        }

        return $out;
    }
}
