<?php

namespace App\Domain\Auth;

use App\Domain\Audit\Enums\ActorType;

/** The authenticated actor of the current request: a user, a tablet or a device. */
final readonly class Principal
{
    public function __construct(
        public ActorType $type,
        public int $id,
        public ?int $tenantId,
    ) {}
}
