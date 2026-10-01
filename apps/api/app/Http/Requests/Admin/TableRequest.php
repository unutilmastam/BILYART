<?php

namespace App\Http\Requests\Admin;

use App\Domain\Branches\Models\Branch;
use App\Domain\Devices\Models\Device;
use App\Domain\Pricing\Models\PricingPlan;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
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
            // Lamp wiring: relay channel of an ESP32 in the same branch (null = not wired).
            'deviceId' => ['sometimes', 'nullable', 'string', 'size:26'],
            'deviceChannel' => ['required_with:deviceId', 'nullable', 'integer', 'between:1,'.Device::MAX_CHANNELS],
        ];
    }

    public function branch(): ?Branch
    {
        return $this->filled('branchId') ? Branch::query()->where('public_id', $this->input('branchId'))->first() : null;
    }

    public function wantsWiring(): bool
    {
        return $this->has('deviceId');
    }

    /** The chosen device (tenant-scoped lookup), null when unwiring; a foreign/unknown id is a validation error. */
    public function device(): ?Device
    {
        if (! $this->filled('deviceId')) {
            return null;
        }

        return Device::query()->where('public_id', $this->input('deviceId'))->first()
            ?? throw new ApiException(ErrorCode::VALIDATION_FAILED, [], ['fields' => ['deviceId' => [__('validation.exists', ['attribute' => 'device'])]]]);
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
