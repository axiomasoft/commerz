<?php

declare(strict_types=1);

namespace Commerz\Sales\Gateways\Contracts;

use Commerz\Sales\Gateways\Data\PaymentCapabilities;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Additive extension of {@see PaymentGateway} advertising its acquiring capabilities.
 */
#[Spi(kind: SpiKind::Implement, since: '0.1')]
interface CapablePaymentGateway extends PaymentGateway
{
    public function capabilities(): PaymentCapabilities;
}
