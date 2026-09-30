<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

/**
 * Portable helpers for constraints the schema builder cannot express.
 * Supported drivers: mysql, mariadb, pgsql (see docs/ARCHITECTURE.md §2a).
 */
final class Constraints
{
    /** Adds a named CHECK constraint (MySQL ≥ 8.0.16, MariaDB ≥ 10.2, PostgreSQL). */
    public static function check(string $table, string $name, string $expression): void
    {
        DB::statement(sprintf('ALTER TABLE %s ADD CONSTRAINT %s CHECK (%s)', self::wrap($table), self::wrap($name), $expression));
    }

    /** Builds "col IN ('A','B')" for CHECK constraints from a list of allowed values. */
    public static function in(string $column, array $values): string
    {
        $quoted = array_map(static fn (string $v): string => "'".str_replace("'", "''", $v)."'", $values);

        return sprintf('%s IN (%s)', self::wrap($column), implode(', ', $quoted));
    }

    public static function driver(): string
    {
        return DB::connection()->getDriverName();
    }

    public static function isPostgres(): bool
    {
        return self::driver() === 'pgsql';
    }

    public static function wrap(string $identifier): string
    {
        return self::isPostgres() ? '"'.$identifier.'"' : '`'.$identifier.'`';
    }
}
