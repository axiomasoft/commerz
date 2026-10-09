<?php

declare(strict_types=1);

namespace Commerz\Promotions\Definitions\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use DateTimeImmutable;

/** The period `[startsAt, endsAt)` a promotion works in; a missing bound is open. */
#[Api(since: '0.1')]
final readonly class PromotionWindow
{
    public function __construct(public ?DateTimeImmutable $startsAt = null, public ?DateTimeImmutable $endsAt = null)
    {
        if ($startsAt !== null && $endsAt !== null && $startsAt >= $endsAt) {
            throw new InvalidCommand('window', 'startsAt must be before endsAt', 'promotions.invalid_window');
        }
    }
}
