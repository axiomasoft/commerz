<?php

declare(strict_types=1);

namespace Commerz\Sales\Numbering\Contracts;

use Commerz\Attributes\Spi;
use Commerz\Attributes\SpiKind;
use Commerz\Sales\Numbering\Data\OrderNumbering;

/** Numbers placed orders, a contribution of kind `order_number_generator`. Called inside the placement transaction: no network. */
#[Spi(kind: SpiKind::Implement, since: '0.1')]
interface OrderNumberGenerator
{
    public const string KIND = 'order_number_generator';

    /** Contribution key, e.g. `sequence`. */
    public function key(): string;

    /** The visible number: `[A-Za-z0-9][A-Za-z0-9-]{0,63}`, unique within the store. */
    public function next(OrderNumbering $numbering): string;
}
