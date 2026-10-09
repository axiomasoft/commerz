<?php

declare(strict_types=1);

namespace Commerz\Pricing\Common\Internal;

use Commerz\Errors\InvalidCommand;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Throwable;

/** Validity windows `[from, until)` of prices and tax rates and the SQLSTATE of their constraint violations. */
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
