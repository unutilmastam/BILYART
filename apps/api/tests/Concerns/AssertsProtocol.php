<?php

namespace Tests\Concerns;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Contract tests: real API responses must match packages/protocol/schemas
 * (the single source shared with the tablet app and the ESP32 firmware).
 */
trait AssertsProtocol
{
    private static ?Validator $protocolValidator = null;

    protected function assertMatchesProtocol(string $schema, array $data): void
    {
        if (self::$protocolValidator === null) {
            $validator = new Validator;
            $validator->resolver()->registerPrefix('https://protocol.bilyart/', dirname(base_path(), 2).'/packages/protocol/schemas/');
            self::$protocolValidator = $validator;
        }
        $result = self::$protocolValidator->validate(json_decode(json_encode($data)), "https://protocol.bilyart/{$schema}.schema.json");
        $this->assertTrue(
            $result->isValid(),
            $result->isValid() ? '' : "Response does not match protocol schema {$schema}: ".json_encode((new ErrorFormatter)->format($result->error()), JSON_PRETTY_PRINT)
        );
    }
}
