<?php

declare(strict_types=1);

namespace Commerz\Sales\Returns\Data;

use Commerz\Attributes\Api;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Returns\Enums\ReturnStatus;
use DateTimeImmutable;

/** One return of an order. */
#[Api(since: '0.1')]
final readonly class ReturnView
{
    /** @param  list<ReturnLineView>  $lines  by line id */
    public function __construct(
        public ReturnId $id,
        public OrderId $order,
        public ReturnStatus $status,
        public array $lines,
        public ?string $reason,
        public ?string $locationCode,
        public ?DateTimeImmutable $receivedAt,
        public DateTimeImmutable $createdAt,
    ) {}
}
