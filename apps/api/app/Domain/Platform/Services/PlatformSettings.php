<?php

namespace App\Domain\Platform\Services;

use App\Domain\Platform\Models\SystemSetting;
use App\Domain\Users\Models\User;

/** Whitelisted platform settings (Super Admin → Settings). Unknown keys are never stored. */
final class PlatformSettings
{
    /** key => default */
    public const DEFAULTS = [
        'support_contact' => '',          // phone / Telegram shown to clients
        'payment_instructions' => '',     // shown to clients whose subscription is inactive (spec §29)
        'default_branch_limit' => 1,
        'reminder_days' => [5, 3, 1, 0],  // subscription reminders (spec §40)
    ];

    public function all(): array
    {
        $stored = SystemSetting::query()->whereIn('key', array_keys(self::DEFAULTS))->pluck('value', 'key')->all();

        return array_merge(self::DEFAULTS, $stored);
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    public function update(array $values, User $actor): array
    {
        foreach (array_intersect_key($values, self::DEFAULTS) as $key => $value) {
            SystemSetting::query()->updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $actor->id]);
        }

        return $this->all();
    }
}
