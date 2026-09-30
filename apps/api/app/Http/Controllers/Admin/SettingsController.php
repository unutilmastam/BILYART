<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\TenantSettings;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TenantSettingsRequest;

final class SettingsController extends Controller
{
    private const MAP = [
        'privacyNotice' => 'privacy_notice',
        'photoRetentionDays' => 'photo_retention_days',
        'warningText' => 'warning_text',
        'warnBeforeMinutes' => 'warn_before_minutes',
        'locale' => 'locale',
        'operatorsCanViewPhotos' => 'operators_can_view_photos',
    ];

    public function __construct(
        private readonly TenantSettings $settings,
        private readonly TenantContext $context,
    ) {}

    public function show(): array
    {
        return $this->present($this->settings->for($this->tenant()));
    }

    public function update(TenantSettingsRequest $request): array
    {
        $values = [];
        foreach (self::MAP as $in => $key) {
            if ($request->has($in)) {
                $values[$key] = $in === 'operatorsCanViewPhotos' ? $request->boolean($in)
                    : (in_array($in, ['photoRetentionDays', 'warnBeforeMinutes'], true) ? (int) $request->input($in) : $request->input($in));
            }
        }

        return $this->present($this->settings->update($this->tenant(), $values));
    }

    private function tenant(): Tenant
    {
        return Tenant::query()->findOrFail($this->context->requireTenantId());
    }

    private function present(array $settings): array
    {
        $out = [];
        foreach (self::MAP as $out_key => $key) {
            $out[$out_key] = $settings[$key];
        }

        return $out;
    }
}
