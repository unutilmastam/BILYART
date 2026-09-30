<?php

namespace Tests\Concerns;

use App\Domain\Branches\Models\Branch;
use App\Domain\Devices\Models\Device;
use App\Domain\Pricing\Models\PricingPlan;
use App\Domain\Tables\Models\BilliardTable;
use App\Domain\Tablets\Models\Tablet;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A ready-to-play hall: tenant → branch (24/7) → plan (20 000/h) → table 1 with a
 * paired, online ESP32 → paired kiosk tablet with a known bearer token.
 */
trait BuildsSessionFixtures
{
    /** @return array{tenant: Tenant, branch: Branch, plan: PricingPlan, table: BilliardTable, device: Device, tablet: Tablet, token: string} */
    protected function hall(array $tenantAttrs = [], array $settings = []): array
    {
        return $this->asSystem(function () use ($tenantAttrs, $settings): array {
            $tenant = Tenant::factory()->create($tenantAttrs + ['settings' => $settings]);
            $branch = Branch::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Markaz', 'timezone' => 'Asia/Tashkent']);
            $plan = PricingPlan::factory()->create(['tenant_id' => $tenant->id, 'price_per_hour' => 20000, 'rounding_step' => 1000, 'allowed_durations' => [10, 30, 60, 120]]);
            $table = BilliardTable::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'number' => 1, 'name' => '1-stol', 'pricing_plan_id' => $plan->id]);
            $device = $this->pairedDevice($tenant, $branch, $table);
            $token = Str::random(43);
            $tablet = new Tablet(['name' => 'Kiosk']);
            $tablet->forceFill([
                'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'device_code' => 'TABLET-'.strtoupper(Str::random(6)),
                'status' => 'PAIRED', 'token_hash' => hash('sha256', $token), 'registered_at' => now(), 'paired_at' => now(),
            ])->save();

            return compact('tenant', 'branch', 'plan', 'table', 'device', 'tablet', 'token');
        });
    }

    protected function pairedDevice(Tenant $tenant, Branch $branch, BilliardTable $table, bool $online = true): Device
    {
        $hw = strtoupper(bin2hex(random_bytes(6)));
        $device = new Device(['firmware_version' => '1.0.0']);
        $device->forceFill([
            'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'table_id' => $table->id, 'active_table_id' => $table->id,
            'hardware_id' => $hw, 'active_hardware_id' => $hw, 'device_code' => Device::codeFor($hw),
            'status' => 'PAIRED', 'registered_at' => now(), 'paired_at' => now(),
            'last_seen_at' => $online ? now() : now()->subMinutes(5),
        ])->save();

        return $device;
    }

    /** Keeps the fake device "online" (as if it polled just now). */
    protected function touchDevice(Device $device): void
    {
        $this->asSystem(fn () => Device::query()->whereKey($device->id)->update(['last_seen_at' => now()]));
    }

    /** Records a customer photo row for the session (the real upload path is covered in PhotoTest). */
    protected function attachPhoto(string $sessionPublicId): void
    {
        $this->asSystem(function () use ($sessionPublicId): void {
            $s = DB::table('game_sessions')->where('public_id', $sessionPublicId)->first(['id', 'tenant_id']);
            DB::table('session_photos')->insert([
                'public_id' => (string) Str::ulid(), 'tenant_id' => $s->tenant_id, 'session_id' => $s->id,
                'storage_path' => "tenants/{$s->tenant_id}/sessions/{$sessionPublicId}/".strtoupper((string) Str::ulid()).'.jpg',
                'mime_type' => 'image/jpeg', 'size' => 1, 'width' => 640, 'height' => 480, 'sha256' => str_repeat('0', 64), 'created_at' => now(),
            ]);
        });
    }

    /** Photo + start, as the kiosk does it (a photo is always required). */
    protected function tabletStart(string $token, string $sessionPublicId)
    {
        $this->attachPhoto($sessionPublicId);

        return $this->tabletPost($token, "/api/tablet/sessions/{$sessionPublicId}/start");
    }

    protected function tabletPost(string $token, string $uri, array $body = [], ?string $key = null)
    {
        // Per-request header (withHeaders() would leak the key into later requests of the same test).
        return $this->withToken($token)->postJson($uri, $body, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }
}
