<?php

declare(strict_types=1);

namespace Commerz\Sales\Common\Internal;

use Commerz\Money\Currency;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Orders\Enums\OrderState;
use Commerz\Sales\Orders\Enums\PaymentState;
use DateTimeImmutable;
use Packman\Attributes\Internal;

/** A stored order with its lines in position order, as read under its lock (`OrderLocks`). */
#[Internal]
final readonly class OrderRow
{
    /** @param  list<OrderLineRow>  $lines */
    public function __construct(
        public OrderId $id,
        public string $number,
        public OrderState $state,
        public PaymentState $paymentState,
        public Currency $currency,
        public ?DateTimeImmutable $paymentDueAt,
        public array $lines,
        public int $version = 1,
    ) {}

    public function fulfilled(): int
    {
        return array_sum(array_map(static fn (OrderLineRow $line): int => $line->fulfilled, $this->lines));
    }

    public function outstanding(): int
    {
        return array_sum(array_map(static fn (OrderLineRow $line): int => $line->outstanding(), $this->lines));
    }

    public function line(string $id): ?OrderLineRow
    {
        return array_find($this->lines, static fn (OrderLineRow $line): bool => $line->id->value === $id);
    }
}
