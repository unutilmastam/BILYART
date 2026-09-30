<?php

namespace Tests\Unit\Logging;

use App\Support\Logging\Redactor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class RedactorTest extends TestCase
{
    #[Test]
    public function sensitive_keys_are_masked_recursively(): void
    {
        $out = Redactor::redact([
            'login' => 'ali',
            'password' => 'x',
            'currentPassword' => 'y',
            'Authorization' => 'Bearer z',
            'deviceToken' => 't',
            'bot_token' => 'b',
            'registrationSecret' => 's',
            'nested' => ['pollToken' => 'p', 'ok' => 1, 'deeper' => ['password_confirmation' => 'c']],
            'code' => 'TABLE_UNAVAILABLE',
        ]);

        $this->assertSame('ali', $out['login']);
        $this->assertSame('TABLE_UNAVAILABLE', $out['code']);
        $this->assertSame(1, $out['nested']['ok']);
        foreach ([$out['password'], $out['currentPassword'], $out['Authorization'], $out['deviceToken'], $out['bot_token'],
            $out['registrationSecret'], $out['nested']['pollToken'], $out['nested']['deeper']['password_confirmation']] as $v) {
            $this->assertSame(Redactor::MASK, $v);
        }
    }
}
