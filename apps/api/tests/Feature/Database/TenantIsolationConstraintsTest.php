<?php

namespace Tests\Feature\Database;

use App\Domain\Branches\Models\Branch;
use App\Domain\Users\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsTenantData;
use Tests\TestCase;

/** Spec §2/§49: even with an application bug, the DB refuses cross-tenant links (composite FKs). */
class TenantIsolationConstraintsTest extends TestCase
{
    use BuildsTenantData, RefreshDatabase;

    #[Test]
    public function a_table_cannot_be_linked_to_another_tenants_branch(): void
    {
        $a = $this->tenantWithTable();
        $b = $this->tenantWithTable();

        $this->expectException(QueryException::class);
        DB::table('billiard_tables')->insert([
            'public_id' => (string) Str::ulid(),
            'tenant_id' => $a['tenant']->id,
            'branch_id' => $b['branch']->id,
            'number' => 7,
            'name' => '7-stol',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function a_session_cannot_reference_another_tenants_table(): void
    {
        $a = $this->tenantWithTable();
        $b = $this->tenantWithTable();

        $this->expectException(QueryException::class);
        $this->insertSession($a['tenant']->id, $a['branch']->id, $b['table']->id, 'ACTIVE');
    }

    #[Test]
    public function a_session_cannot_mix_branch_and_table_of_different_branches(): void
    {
        $a = $this->tenantWithTable();
        $otherBranch = $this->asSystem(fn () => Branch::factory()->create(['tenant_id' => $a['tenant']->id]));

        $this->expectException(QueryException::class);
        $this->insertSession($a['tenant']->id, $otherBranch->id, $a['table']->id, 'ACTIVE');
    }

    #[Test]
    public function a_device_cannot_be_paired_to_another_tenants_branch(): void
    {
        $a = $this->tenantWithTable();
        $b = $this->tenantWithTable();

        $this->expectException(QueryException::class);
        DB::table('devices')->insert([
            'public_id' => (string) Str::ulid(),
            'hardware_id' => 'A8F4C1B2D3E4',
            'active_hardware_id' => 'A8F4C1B2D3E4',
            'device_code' => 'ESP32-B2D3E4',
            'tenant_id' => $a['tenant']->id,
            'branch_id' => $b['branch']->id,
            'status' => 'PAIRED',
            'registered_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function a_table_cannot_be_wired_to_a_device_of_another_branch_or_tenant(): void
    {
        $a = $this->tenantWithTable();
        $b = $this->tenantWithTable();
        $deviceB = DB::table('devices')->insertGetId([
            'public_id' => (string) Str::ulid(), 'hardware_id' => 'A8F4C1B2D3E4', 'active_hardware_id' => 'A8F4C1B2D3E4',
            'device_code' => 'ESP32-B2D3E4', 'tenant_id' => $b['tenant']->id, 'branch_id' => $b['branch']->id, 'channel_count' => 4,
            'status' => 'PAIRED', 'registered_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('billiard_tables')->where('id', $a['table']->id)->update(['device_id' => $deviceB, 'device_channel' => 1]);
    }

    #[Test]
    public function a_photo_cannot_reference_another_tenants_session(): void
    {
        $a = $this->tenantWithTable();
        $b = $this->tenantWithTable();
        $sessionB = $this->insertSession($b['tenant']->id, $b['branch']->id, $b['table']->id, 'COMPLETED');

        $this->expectException(QueryException::class);
        DB::table('session_photos')->insert([
            'public_id' => (string) Str::ulid(),
            'tenant_id' => $a['tenant']->id,
            'session_id' => $sessionB,
            'storage_path' => 'x.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 1,
            'width' => 320,
            'height' => 320,
            'sha256' => str_repeat('a', 64),
            'created_at' => now(),
        ]);
    }

    #[Test]
    public function a_user_cannot_mark_payment_of_another_tenants_session(): void
    {
        $a = $this->tenantWithTable();
        $b = $this->tenantWithTable();
        $userB = $this->asSystem(fn () => User::factory()->create(['tenant_id' => $b['tenant']->id]));
        $sessionA = $this->insertSession($a['tenant']->id, $a['branch']->id, $a['table']->id, 'COMPLETED');

        $this->expectException(QueryException::class);
        DB::table('game_sessions')->where('id', $sessionA)->update(['payment_marked_by' => $userB->id, 'payment_status' => 'PAID']);
    }

    #[Test]
    public function super_admin_must_not_have_a_tenant_and_client_users_must(): void
    {
        $a = $this->tenantWithTable();
        $insert = fn (array $row) => DB::table('users')->insert(array_merge([
            'public_id' => (string) Str::ulid(), 'name' => 'x', 'password' => 'x', 'is_active' => true,
            'failed_logins' => 0, 'created_at' => now(), 'updated_at' => now(),
        ], $row));

        foreach ([
            ['role' => 'SUPER_ADMIN', 'tenant_id' => $a['tenant']->id, 'login' => 'sa1'],
            ['role' => 'CLIENT_OWNER', 'tenant_id' => null, 'login' => 'co1'],
            ['role' => 'HACKER', 'tenant_id' => $a['tenant']->id, 'login' => 'h1'],
        ] as $row) {
            try {
                DB::transaction(fn () => $insert($row)); // savepoint keeps PostgreSQL's transaction usable
                $this->fail('Row should have been rejected: '.json_encode($row));
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function the_same_rows_are_accepted_when_the_tenant_chain_is_consistent(): void
    {
        $a = $this->tenantWithTable();
        $user = $this->asSystem(fn () => User::factory()->create(['tenant_id' => $a['tenant']->id]));

        DB::table('billiard_tables')->insert([
            'public_id' => (string) Str::ulid(), 'tenant_id' => $a['tenant']->id, 'branch_id' => $a['branch']->id,
            'number' => 7, 'name' => '7-stol', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $device = DB::table('devices')->insertGetId([
            'public_id' => (string) Str::ulid(), 'hardware_id' => 'A8F4C1B2D3E4', 'active_hardware_id' => 'A8F4C1B2D3E4',
            'device_code' => 'ESP32-B2D3E4', 'tenant_id' => $a['tenant']->id, 'branch_id' => $a['branch']->id, 'channel_count' => 4,
            'status' => 'PAIRED', 'registered_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('billiard_tables')->where('id', $a['table']->id)->update(['device_id' => $device, 'device_channel' => 2]);
        $session = $this->insertSession($a['tenant']->id, $a['branch']->id, $a['table']->id, 'COMPLETED');
        DB::table('session_photos')->insert([
            'public_id' => (string) Str::ulid(), 'tenant_id' => $a['tenant']->id, 'session_id' => $session,
            'storage_path' => 'x.jpg', 'mime_type' => 'image/jpeg', 'size' => 1, 'width' => 320, 'height' => 320,
            'sha256' => str_repeat('a', 64), 'created_at' => now(),
        ]);
        DB::table('game_sessions')->where('id', $session)->update(['payment_marked_by' => $user->id, 'payment_status' => 'PAID']);

        $this->assertSame(2, DB::table('billiard_tables')->count());
        $this->assertSame(1, DB::table('session_photos')->count());
    }
}
