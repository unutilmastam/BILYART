<?php

namespace App\Support\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/** Monolog processor: redacts sensitive keys in context/extra and adds the request id. */
final class RedactSensitiveProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $extra = Redactor::redact($record->extra);
        $request = app()->bound('request') ? app('request') : null;
        if ($request !== null && $request->attributes->has('request_id')) {
            $extra['request_id'] = $request->attributes->get('request_id');
        }

        return $record->with(context: Redactor::redact($record->context), extra: $extra);
    }
}
