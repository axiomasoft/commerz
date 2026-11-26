<?php

declare(strict_types=1);

namespace Commerz\Sales\Payments\Contracts;

use Commerz\Sales\Payments\Data\PaymentCustomizationContext;
use Commerz\Sales\Payments\Data\PaymentMethodOperation;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Pure checkout payment customization SPI (`payment_customization`): hides, renames, or reorders available payment
 * methods by cart, market, channel, amount, or customer.
 *
 * Rules run deterministically ordered by `priority() DESC, key() ASC`.
 */
#[Spi(kind: SpiKind::Implement, since: '0.1')]
interface PaymentCustomization
{
    public const string KIND = 'payment_customization';

    /** Stable contribution key, e.g. `b2b_invoice_only` or `eu_sort`. */
    public function key(): string;

    /** Higher priority runs first; ties break by `key()` ascending. */
    public function priority(): int;

    /**
     * Returns declarative hide, rename, and move operations for the candidate methods in `$context`.
     *
     * @return list<PaymentMethodOperation>
     */
    public function customize(PaymentCustomizationContext $context): array;
}
