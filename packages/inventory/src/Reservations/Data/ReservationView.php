<?php

declare(strict_types=1);

namespace Commerz\Inventory\Reservations\Data;

use Commerz\Inventory\Reservations\Enums\ReservationState;
use Commerz\Stores\Data\ChannelId;
use DateTimeImmutable;
use Packman\Attributes\Api;

/**
 * What a demand holds. A demand reserved again after everything was released or expired starts a new `generation`;
 * the view shows only the lines of the current one. Untracked and unlimited sellables hold nothing and have no lines.
 */
#[Api(since: '0.1')]
final readonly class ReservationView
{
    /** @param  list<ReservationLineView>  $lines  in allocation order */
    public function __construct(
        public ReservationId $id,
        public string $demand,
        public ChannelId $channel,
        public ReservationState $state,
        public int $generation,
        public ?DateTimeImmutable $expiresAt,
        public array $lines,
    ) {}
}
