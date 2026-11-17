<?php

declare(strict_types=1);

namespace Commerz\Sales\Numbering\Contracts;

use Commerz\Sales\Numbering\Data\OrderNumbering;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Numbers placed orders, a contribution of kind `order_number_generator`. Called inside the placement transaction: no network. */
#[Spi(kind: SpiKind::Implement, since: '0.1')]
interface OrderNumberGenerator
{
    public const string KIND = 'order_number_generator';

    /** Contribution key, e.g. `sequence`. */
    public function key(): string;

    /**
     * The visible number: 1..64 characters of `A-Za-z0-9-` with at least one letter or digit (a store prefix may
     * start with a hyphen), unique within the store.
     */
    public function next(OrderNumbering $numbering): string;
}
