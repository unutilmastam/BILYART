<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Platform\Services\PlatformSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\UpdatePlatformSettingsRequest;

final class SettingsController extends Controller
{
    private const MAP = [
        'supportContact' => 'support_contact',
        'paymentInstructions' => 'payment_instructions',
        'defaultBranchLimit' => 'default_branch_limit',
        'reminderDays' => 'reminder_days',
    ];

    public function show(PlatformSettings $settings): array
    {
        return $this->present($settings->all());
    }

    public function update(UpdatePlatformSettingsRequest $request, PlatformSettings $settings, AuditLogger $audit): array
    {
        $values = [];
        foreach (self::MAP as $in => $key) {
            if ($request->has($in)) {
                $values[$key] = $request->input($in) ?? '';
            }
        }
        $all = $settings->update($values, $request->user());
        $audit->log('platform.settings_updated', null, ['keys' => array_keys($values)], ['tenant_id' => null]);

        return $this->present($all);
    }

    private function present(array $all): array
    {
        $out = [];
        foreach (self::MAP as $out_key => $key) {
            $out[$out_key] = $all[$key];
        }

        return $out;
    }
}
