<?php

namespace App\Domain\Health;

use App\Domain\Photos\Storage\PhotoStorage;
use App\Domain\Platform\Models\SystemSetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * spec §56. Each check returns {status: ok|warn|fail, ...details}. Details are
 * shown only to the Super Admin or a HEALTH_TOKEN holder; the public /health
 * returns only the overall status.
 */
final class HealthService
{
    public const SCHEDULER_KEY = 'health:scheduler:last_run';

    public function __construct(private readonly PhotoStorage $photos) {}

    public function all(): array
    {
        $checks = ['db' => $this->db(), 'storage' => $this->storage(), 'messaging' => $this->messaging(), 'backups' => $this->backups()];
        $overall = collect($checks)->contains(fn ($c) => $c['status'] === 'fail') ? 'fail'
            : (collect($checks)->contains(fn ($c) => $c['status'] === 'warn') ? 'warn' : 'ok');

        return ['status' => $overall, 'checkedAt' => now()->toIso8601ZuluString(), 'checks' => $checks];
    }

    public function db(): array
    {
        try {
            $start = microtime(true);
            DB::select('select 1');

            return ['status' => 'ok', 'driver' => DB::connection()->getDriverName(), 'latencyMs' => (int) round((microtime(true) - $start) * 1000)];
        } catch (\Throwable) {
            return ['status' => 'fail', 'error' => 'DB_UNREACHABLE'];
        }
    }

    public function storage(): array
    {
        $ok = $this->photos->probe();
        // Absolute free space: on shared hosting the percentage of the whole server disk is meaningless.
        $free = @disk_free_space(storage_path());
        $freeMb = $free === false ? null : (int) ($free / 1048576);

        return [
            'status' => ! $ok ? 'fail' : (($freeMb !== null && $freeMb < 500) ? 'warn' : 'ok'),
            'writable' => $ok,
            'freeMb' => $freeMb,
        ];
    }

    /** Scheduler heartbeat + delivery backlogs (device commands, Telegram). */
    public function messaging(): array
    {
        $last = Cache::get(self::SCHEDULER_KEY);
        $age = $last ? now()->getTimestamp() - (int) $last : null;
        $pendingTelegram = DB::table('notification_logs')->where('channel', 'TELEGRAM')->where('status', 'PENDING')->where('created_at', '<', now()->subMinutes(5))->count();
        $stuckCommands = DB::table('device_commands')->whereIn('status', ['PENDING', 'SENT'])->where('created_at', '<', now()->subMinutes(5))->count();
        $status = $age === null || $age > 300 ? 'fail' : (($pendingTelegram > 20 || $stuckCommands > 50) ? 'warn' : 'ok');

        return [
            'status' => $status,
            'transport' => config('devices.transport'),
            'schedulerLastRunSecondsAgo' => $age,
            'pendingTelegramOver5Min' => $pendingTelegram,
            'deviceCommandsOver5Min' => $stuckCommands,
        ];
    }

    public function backups(): array
    {
        $run = SystemSetting::query()->find('backup.last_run')?->value;
        $verify = SystemSetting::query()->find('backup.last_verify')?->value;
        $runAge = isset($run['at']) ? CarbonImmutable::parse($run['at'])->diffInHours(now()) : null;
        $status = match (true) {
            $run === null || ! ($run['ok'] ?? false) => 'fail',
            $runAge > 30 || ($verify !== null && ! ($verify['ok'] ?? false)) => 'warn',
            default => 'ok',
        };

        return ['status' => $status, 'lastRun' => $run, 'lastVerify' => $verify];
    }
}
