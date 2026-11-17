<?php

declare(strict_types=1);

namespace Commerz\Inventory\Transfers\Data;

use Commerz\Inventory\Locations\Data\LocationId;
use Commerz\Inventory\Transfers\Enums\TransferState;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class TransferView
{
    /** @param  list<TransferLineView>  $lines  by SKU */
    public function __construct(
        public TransferId $id,
        public LocationId $from,
        public string $fromCode,
        public LocationId $to,
        public string $toCode,
        public TransferState $state,
        public ?string $reference,
        public array $lines,
        public ?DateTimeImmutable $dispatchedAt,
        public ?DateTimeImmutable $completedAt,
    ) {}
}
