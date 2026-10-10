<?php

declare(strict_types=1);

namespace Commerz\Sales\Common\Internal;

use DateTimeImmutable;
use DateTimeZone;

/** D33: a journal row's `claimed_at` is a lease; the network operation behind it is owned by one process while it is fresh. */
final class Lease
{
    public static function isFresh(string|DateTimeImmutable $claimedAt, int $seconds): bool
    {
        $claimedAt = is_string($claimedAt) ? new DateTimeImmutable($claimedAt) : $claimedAt;
        $since = new DateTimeImmutable('now', new DateTimeZone('UTC'))->modify("-{$seconds} seconds");

        return $claimedAt > $since;
    }
}
