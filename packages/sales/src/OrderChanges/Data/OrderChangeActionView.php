<?php

declare(strict_types=1);

namespace Commerz\Sales\OrderChanges\Data;

use Commerz\Sales\OrderChanges\Enums\OrderChangeActionType;
use DateTimeImmutable;
use Packman\Attributes\Api;

/** Read model of a stored action inside an OrderChange. */
#[Api(since: '0.1')]
final readonly class OrderChangeActionView
{
    /** @param  array<string, mixed>  $payload */
    public function __construct(
        public OrderChangeActionId $id,
        public int $position,
        public OrderChangeActionType $type,
        public array $payload,
        public DateTimeImmutable $createdAt,
    ) {}
}
