<?php

declare(strict_types=1);

namespace Commerz\Pricing\Prices\Internal;

use Closure;
use DateTimeImmutable;
use Packman\Attributes\Internal;

/**
 * Scoped in-memory buffer of staged price rows active only during `StagedPrices::simulate()`.
 * Leaves zero state outside the simulation closure.
 */
#[Internal]
final class StagedPriceOverlay
{
    /**
     * @var list<array{
     *     id: string,
     *     price_list_id: string,
     *     sellable_ref: string,
     *     customer_group: string|null,
     *     min_quantity: int,
     *     amount_minor: int,
     *     compare_at_minor: int|null,
     *     currency: string,
     *     valid_from: DateTimeImmutable|null,
     *     valid_until: DateTimeImmutable|null,
     * }>
     */
    private array $entries = [];

    /**
     * @template T
     *
     * @param  list<array{
     *     id: string,
     *     price_list_id: string,
     *     sellable_ref: string,
     *     customer_group: string|null,
     *     min_quantity: int,
     *     amount_minor: int,
     *     compare_at_minor: int|null,
     *     currency: string,
     *     valid_from: DateTimeImmutable|null,
     *     valid_until: DateTimeImmutable|null,
     * }>  $entries
     * @param  Closure(): T  $callback
     * @return T
     */
    public function withEntries(array $entries, Closure $callback): mixed
    {
        $previous = $this->entries;
        $this->entries = $entries;

        try {
            return $callback();
        } finally {
            $this->entries = $previous;
        }
    }

    /**
     * @return list<array{
     *     id: string,
     *     price_list_id: string,
     *     sellable_ref: string,
     *     customer_group: string|null,
     *     min_quantity: int,
     *     amount_minor: int,
     *     compare_at_minor: int|null,
     *     currency: string,
     *     valid_from: DateTimeImmutable|null,
     *     valid_until: DateTimeImmutable|null,
     * }>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    public function isActive(): bool
    {
        return $this->entries !== [];
    }
}
