<?php

namespace App\Domain\Pricing\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Pricing\Models\PricingPlan;

final class PricingPlanService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function create(array $attributes): PricingPlan
    {
        $plan = PricingPlan::query()->create($attributes);
        $this->audit->log('pricing.created', $plan, $plan->only(['name', 'price_per_hour', 'rounding_step', 'allowed_durations']));

        return $plan;
    }

    public function update(PricingPlan $plan, array $attributes): PricingPlan
    {
        $keys = ['name', 'price_per_hour', 'rounding_step', 'allowed_durations', 'is_active', 'branch_id'];
        $old = $plan->only($keys);
        $plan->fill($attributes)->save();
        $this->audit->log('pricing.changed', $plan, ['from' => $old, 'to' => $plan->only($keys)]);

        return $plan;
    }
}
