<?php

namespace Tests\Feature\Security;

use App\Domain\Audit\Models\AuditLog;
use App\Support\Logging\RedactingTap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/** Spec §43 item 13. */
class PasswordNeverLoggedTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    #[Test]
    public function passwords_never_reach_logs_audit_rows_or_the_database_in_clear(): void
    {
        $logFile = storage_path('logs/test-passwords.log');
        @unlink($logFile);
        config(['logging.channels.pwtest' => [
            'driver' => 'single', 'path' => $logFile, 'level' => 'debug',
            'tap' => [RedactingTap::class],
        ]]);
        config(['logging.default' => 'pwtest']);
        Log::forgetChannel('pwtest');

        $secretWrong = 'Wrong-Pa55word-XYZ';
        $user = $this->tenantUser('CLIENT_OWNER', null, ['login' => 'ali']);

        $this->postJson('/api/auth/login', ['login' => 'ali', 'password' => $secretWrong])->assertStatus(401);
        $this->postJson('/api/auth/login', ['login' => 'ali', 'password' => 'password-Secret-123'])->assertOk();
        // An application that logs a request context must still not leak it.
        Log::warning('login debug', ['password' => $secretWrong, 'nested' => ['new_password' => 'NewSecret-999'], 'authorization' => 'Bearer abc']);

        $log = (string) @file_get_contents($logFile);
        $this->assertStringContainsString('login debug', $log);
        foreach ([$secretWrong, 'password-Secret-123', 'NewSecret-999', 'Bearer abc'] as $secret) {
            $this->assertStringNotContainsString($secret, $log);
        }

        $audit = $this->asSystem(fn () => AuditLog::query()->get()->toJson());
        $this->assertStringNotContainsString($secretWrong, $audit);
        $this->assertStringNotContainsString('password-Secret-123', $audit);

        $stored = DB::table('users')->where('id', $user->id)->value('password');
        $this->assertNotSame('password-Secret-123', $stored);
        $this->assertStringStartsWith('$2y$', $stored);
        @unlink($logFile);
    }
}
