<?php

namespace App\Domain\Auth;

/** Request-scoped holder of the authenticated Principal (set by the auth middlewares). */
final class CurrentPrincipal
{
    private ?Principal $principal = null;

    public function set(?Principal $principal): void
    {
        $this->principal = $principal;
    }

    public function get(): ?Principal
    {
        return $this->principal;
    }
}
