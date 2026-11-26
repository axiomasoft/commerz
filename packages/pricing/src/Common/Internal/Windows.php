<?php

declare(strict_types=1);

namespace Commerz\Pricing\Common\Internal;

use Commerz\Errors\InvalidCommand;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Packman\Attributes\Internal;
use Throwable;

/** Validity windows `[from, until)` of prices and tax rates and the SQLSTATE of their constraint violations. */
#[Internal]
final class Windows
{
    private const string UNIQUE_VIOLATION = '23505';

    private const string EXCLUSION_VIOLATION = '23P01';

    /** A missing bound is open; with both given, the window must not be empty. */
    public static function assertOrdered(?DateTimeImmutable $from, ?DateTimeImmutable $until): void
    {
        if ($from !== null && $until !== null && $from >= $until) {
            throw new InvalidCommand('validUntil', 'must be after validFrom', 'pricing.invalid_window');
        }
    }

    /** The text of a timestamp as the `timestamptz(6)` columns take it; a missing bound stays open. */
    public static function format(?DateTimeImmutable $at): ?string
    {
        return $at?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }

    /** Queues the writers of one key until the current transaction ends (a transaction advisory lock). */
    public static function lock(ConnectionInterface $db, string $key): void
    {
        if (! $db instanceof Connection || $db->getDriverName() === 'pgsql') {
            $db->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [$key]);
        }
    }

    /** A new window overlaps an existing one of the same key (`EXCLUDE USING gist`). */
    public static function isExclusionViolation(Throwable $e): bool
    {
        return self::sqlState($e) === self::EXCLUSION_VIOLATION;
    }

    public static function isUniqueViolation(Throwable $e): bool
    {
        return self::sqlState($e) === self::UNIQUE_VIOLATION;
    }

    private static function sqlState(Throwable $e): ?string
    {
        if (! $e instanceof QueryException) {
            return null;
        }

        $state = $e->errorInfo[0] ?? $e->getCode();

        return is_string($state) ? $state : null;
    }
}
