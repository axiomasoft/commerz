<?php

declare(strict_types=1);

namespace Commerz\Sales\Orders\Data;

use Commerz\Identity\ActorRef;
use DateTimeImmutable;
use Packman\Attributes\Api;

/** An entry of the append-only history of an order. */
#[Api(since: '0.1')]
final readonly class OrderEventView
{
    /**
     * @param  int  $id  grows with every entry
     * @param  string  $type  e.g. `placed`
     * @param  array<string, mixed>  $data  JSON facts of the entry
     */
    public function __construct(
        public int $id,
        public OrderId $order,
        public string $type,
        public ?ActorRef $actor,
        public array $data,
        public DateTimeImmutable $createdAt,
    ) {}
}
