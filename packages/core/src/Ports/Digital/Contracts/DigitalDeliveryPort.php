<?php

declare(strict_types=1);

namespace Commerz\Ports\Digital\Contracts;

use Commerz\Ports\Digital\Data\DigitalDeliveryGrant;
use Commerz\Ports\Digital\Data\GrantDigitalDelivery;
use Commerz\Ports\Digital\Data\RevokeDigitalDelivery;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Host-agnostic digital fulfillment port for downloadable files, license keys, or access entitlements.
 * Implementations must be idempotent per `GrantDigitalDelivery::$reference`.
 */
#[Spi(kind: SpiKind::Implement, since: '0.1')]
interface DigitalDeliveryPort
{
    /** Provisions a digital entitlement, or returns `null` when no digital delivery handler is active. */
    public function grant(GrantDigitalDelivery $command): ?DigitalDeliveryGrant;

    /** Idempotently revokes an entitlement by grant identifier. */
    public function revoke(RevokeDigitalDelivery $command): void;

    public function find(string $grantId): ?DigitalDeliveryGrant;
}
