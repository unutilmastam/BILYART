<?php

use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One ESP32 per branch drives several table lamps (owner decision 2026-09-30).
 * Before: devices.table_id (1 device = 1 table). After: a device belongs to a branch and has
 * channel_count relay channels; each table points to (device_id, device_channel).
 *  - UNIQUE(device_id, device_channel): one table per relay channel.
 *  - FK (tenant_id, branch_id, device_id) → devices(tenant_id, branch_id, id): the device is in the
 *    table's tenant AND branch (and cannot change branch while tables use it).
 *  - FK (tenant_id, branch_id) → branches(tenant_id, id): a device's branch is in its tenant.
 *  - Sessions and commands remember the channel they were started on.
 */
return new class extends Migration
{
    public const MAX_CHANNELS = 8;

    public function up(): void
    {
        $w = [Constraints::class, 'wrap'];

        Schema::table('devices', function (Blueprint $table) {
            $table->unsignedTinyInteger('channel_count')->default(1)->after('device_code');
            $table->unique(['tenant_id', 'branch_id', 'id'], 'devices_tenant_branch_id_unique');
            // The device's own branch must belong to its tenant (before, the table FK guaranteed it).
            $table->foreign(['tenant_id', 'branch_id'], 'devices_branch_fk')->references(['tenant_id', 'id'])->on('branches')->restrictOnDelete();
        });
        Constraints::check('devices', 'devices_channel_count_chk', sprintf('%1$s >= 1 AND %1$s <= %2$d', $w('channel_count'), self::MAX_CHANNELS));

        Schema::table('billiard_tables', function (Blueprint $table) {
            $table->unsignedBigInteger('device_id')->nullable();
            $table->unsignedTinyInteger('device_channel')->nullable();
            $table->unique(['device_id', 'device_channel'], 'billiard_tables_device_channel_unique');
            $table->foreign(['tenant_id', 'branch_id', 'device_id'], 'billiard_tables_device_fk')
                ->references(['tenant_id', 'branch_id', 'id'])->on('devices')->restrictOnDelete();
        });
        Constraints::check('billiard_tables', 'billiard_tables_device_chk', sprintf(
            '(%1$s IS NULL AND %2$s IS NULL) OR (%1$s IS NOT NULL AND %2$s >= 1 AND %2$s <= %3$d)',
            $w('device_id'), $w('device_channel'), self::MAX_CHANNELS
        ));

        Schema::table('game_sessions', fn (Blueprint $table) => $table->unsignedTinyInteger('device_channel')->nullable()->after('device_id'));
        Schema::table('device_commands', fn (Blueprint $table) => $table->unsignedTinyInteger('channel')->nullable());

        // Existing 1:1 pairings become channel 1 of their device.
        foreach (DB::table('devices')->whereNotNull('active_table_id')->get(['id', 'active_table_id']) as $d) {
            DB::table('billiard_tables')->where('id', $d->active_table_id)->update(['device_id' => $d->id, 'device_channel' => 1]);
        }
        DB::table('game_sessions')->whereNotNull('device_id')->update(['device_channel' => 1]);

        Constraints::dropCheck('devices', 'devices_paired_chk');
        Schema::table('devices', function (Blueprint $table) {
            $table->dropForeign('devices_table_fk');
            $table->dropUnique('devices_active_table_id_unique');
        });
        if (Schema::hasIndex('devices', 'devices_table_fk')) {
            Schema::table('devices', fn (Blueprint $table) => $table->dropIndex('devices_table_fk'));
        }
        Schema::table('devices', fn (Blueprint $table) => $table->dropColumn(['table_id', 'active_table_id']));
        Constraints::check('devices', 'devices_paired_chk', sprintf(
            "(%1\$s = 'PAIRED' AND %2\$s IS NOT NULL AND %3\$s IS NOT NULL AND %4\$s IS NOT NULL) OR (%1\$s = 'UNPAIRED' AND %2\$s IS NULL AND %4\$s IS NOT NULL) OR (%1\$s = 'REVOKED' AND %4\$s IS NULL)",
            $w('status'), $w('tenant_id'), $w('branch_id'), $w('active_hardware_id')
        ));
    }

    public function down(): void
    {
        $w = [Constraints::class, 'wrap'];

        Constraints::dropCheck('devices', 'devices_paired_chk');
        Schema::table('devices', function (Blueprint $table) {
            $table->unsignedBigInteger('table_id')->nullable();
            $table->unsignedBigInteger('active_table_id')->nullable()->unique();
        });
        // Back to 1:1: channel 1 (or the lowest channel) of each paired device.
        foreach (DB::table('billiard_tables')->whereNotNull('device_id')->orderBy('device_channel')->get(['id', 'device_id']) as $t) {
            DB::table('devices')->where('id', $t->device_id)->whereNull('table_id')->update(['table_id' => $t->id]);
        }
        DB::table('devices')->where('status', 'PAIRED')->update(['active_table_id' => DB::raw($w('table_id'))]);
        Schema::table('devices', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'branch_id', 'table_id'], 'devices_table_fk')->references(['tenant_id', 'branch_id', 'id'])->on('billiard_tables')->restrictOnDelete();
        });
        Constraints::check('devices', 'devices_paired_chk', sprintf(
            "(%1\$s = 'PAIRED' AND %2\$s IS NOT NULL AND %3\$s IS NOT NULL AND %4\$s IS NOT NULL AND %5\$s = %4\$s AND %6\$s IS NOT NULL) OR (%1\$s = 'UNPAIRED' AND %2\$s IS NULL AND %5\$s IS NULL AND %6\$s IS NOT NULL) OR (%1\$s = 'REVOKED' AND %5\$s IS NULL AND %6\$s IS NULL)",
            $w('status'), $w('tenant_id'), $w('branch_id'), $w('table_id'), $w('active_table_id'), $w('active_hardware_id')
        ));

        Schema::table('device_commands', fn (Blueprint $table) => $table->dropColumn('channel'));
        Schema::table('game_sessions', fn (Blueprint $table) => $table->dropColumn('device_channel'));

        Constraints::dropCheck('billiard_tables', 'billiard_tables_device_chk');
        Schema::table('billiard_tables', function (Blueprint $table) {
            $table->dropForeign('billiard_tables_device_fk');
            $table->dropUnique('billiard_tables_device_channel_unique');
        });
        if (Schema::hasIndex('billiard_tables', 'billiard_tables_device_fk')) {
            Schema::table('billiard_tables', fn (Blueprint $table) => $table->dropIndex('billiard_tables_device_fk'));
        }
        Schema::table('billiard_tables', fn (Blueprint $table) => $table->dropColumn(['device_id', 'device_channel']));

        Constraints::dropCheck('devices', 'devices_channel_count_chk');
        Schema::table('devices', fn (Blueprint $table) => $table->dropForeign('devices_branch_fk'));
        Schema::table('devices', function (Blueprint $table) {
            $table->dropUnique('devices_tenant_branch_id_unique');
            $table->dropColumn('channel_count');
        });
    }
};
