<?php

namespace App\Support\Logging;

use Illuminate\Log\Logger;
use Monolog\Logger as Monolog;

/** `tap` for logging channels (config/logging.php) that installs RedactSensitiveProcessor. */
final class RedactingTap
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();
        if ($monolog instanceof Monolog) {
            $monolog->pushProcessor(new RedactSensitiveProcessor);
        }
    }
}
