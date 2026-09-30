<?php

namespace App\Domain\Tenancy\Exceptions;

use LogicException;

/** Programming error: tenant-owned data touched without a proper tenant context. */
final class TenantContextException extends LogicException {}
