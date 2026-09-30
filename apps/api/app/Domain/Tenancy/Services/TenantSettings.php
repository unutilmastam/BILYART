<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Tenancy\Models\Tenant;

/** Per-tenant business settings stored in tenants.settings (whitelisted keys only). */
final class TenantSettings
{
    public const DEFAULTS = [
        'privacy_notice' => "Sessiya boshlanishida planshet kamerasi orqali bitta surat olinadi. Surat faqat shu billiard zali xodimlariga ko'rinadi va belgilangan muddatdan so'ng o'chiriladi.",
        'photo_retention_days' => 30,
        'photo_required' => true,
        'warning_text' => '{table}-stol, sizda 5 daqiqa vaqtingiz qoldi.',
        'warn_before_minutes' => 5,
        'locale' => 'uz',
        'operators_can_view_photos' => false,
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    public function for(Tenant $tenant): array
    {
        $stored = is_array($tenant->settings) ? $tenant->settings : [];

        return array_merge(self::DEFAULTS, array_intersect_key($stored, self::DEFAULTS));
    }

    public function update(Tenant $tenant, array $values): array
    {
        $clean = array_intersect_key($values, self::DEFAULTS);
        $tenant->forceFill(['settings' => array_merge($this->for($tenant), $clean)])->save();
        $this->audit->log('tenant.settings_updated', $tenant, ['keys' => array_keys($clean)], ['tenant_id' => $tenant->id]);

        return $this->for($tenant);
    }
}
