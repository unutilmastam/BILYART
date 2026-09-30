<?php

namespace Tests\Unit\Sessions;

use App\Domain\Sessions\Enums\SessionStatus as S;
use App\Domain\Sessions\Services\SessionStateMachine;
use App\Support\Http\ApiException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SessionStateMachineTest extends TestCase
{
    #[Test]
    public function only_documented_transitions_are_allowed(): void
    {
        $allowed = [
            'RESERVED' => ['STARTING', 'CANCELLED'],
            'STARTING' => ['ACTIVE', 'FAILED', 'COMPLETING'],
            'ACTIVE' => ['COMPLETING', 'COMPLETED'],
            'COMPLETING' => ['COMPLETED'],
            'COMPLETED' => [],
            'CANCELLED' => [],
            'FAILED' => [],
        ];
        foreach (S::cases() as $from) {
            foreach (S::cases() as $to) {
                $this->assertSame(
                    in_array($to->value, $allowed[$from->value], true),
                    SessionStateMachine::canTransition($from, $to),
                    "{$from->value} → {$to->value}"
                );
            }
        }
    }

    #[Test]
    public function final_states_are_final_and_illegal_moves_throw(): void
    {
        foreach ([S::COMPLETED, S::CANCELLED, S::FAILED] as $final) {
            $this->assertTrue($final->isFinal());
            $this->assertSame([], SessionStateMachine::targets($final));
        }
        $this->expectException(ApiException::class);
        SessionStateMachine::assert(S::COMPLETED, S::ACTIVE);
    }
}
