<?php

namespace App\Http\Requests\Admin;

use App\Domain\Branches\Models\Branch;
use App\Domain\Pricing\Models\PricingPlan;
use Illuminate\Foundation\Http\FormRequest;

/** Public ids from the client are resolved through tenant-scoped models (foreign ids simply don't resolve). */
final class TableRequest extends FormRequest
{
    public function rules(): array
    {
        $creating = $this->isMethod('POST');

        return [
            'branchId' => [$creating ? 'required' : 'prohibited', 'string', 'size:26'],
            'number' => [$creating ? 'required' : 'sometimes', 'integer', 'min:1', 'max:9999'],
            'name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'pricingPlanId' => ['sometimes', 'nullable', 'string', 'size:26'],
            'isActive' => ['sometimes', 'boolean'],
        ];
    }

    public function branch(): ?Branch
    {
        return $this->filled('branchId') ? Branch::query()->where('public_id', $this->input('branchId'))->first() : null;
    }

    /** @return array<string, mixed> */
    public function columns(): array
    {
        $out = [];
        if ($this->has('number')) {
            $out['number'] = (int) $this->input('number');
        }
        if ($this->has('name') || $this->has('number')) {
            $out['name'] = $this->input('name') ?: ($this->input('number').'-stol');
        }
        if ($this->has('isActive')) {
            $out['is_active'] = $this->boolean('isActive');
        }
        if ($this->has('pricingPlanId')) {
            $out['pricing_plan_id'] = $this->filled('pricingPlanId')
                ? (PricingPlan::query()->where('public_id', $this->input('pricingPlanId'))->value('id') ?? -1)
                : null;
        }

        return $out;
    }
}
