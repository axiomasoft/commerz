<?php

declare(strict_types=1);

namespace Commerz\Ports\Digital\Internal;

use Commerz\Ports\Digital\Contracts\DigitalDeliveryPort;
use Commerz\Ports\Digital\Data\DigitalDeliveryGrant;
use Commerz\Ports\Digital\Data\GrantDigitalDelivery;
use Commerz\Ports\Digital\Data\RevokeDigitalDelivery;
use Override;
use Packman\Attributes\Internal;

/** No-op default when the host does not bind a `DigitalDeliveryPort` adapter. */
#[Internal]
final readonly class NullDigitalDelivery implements DigitalDeliveryPort
{
    #[Override]
    public function grant(GrantDigitalDelivery $command): ?DigitalDeliveryGrant
    {
        return null;
    }

    #[Override]
    public function revoke(RevokeDigitalDelivery $command): void {}

    #[Override]
    public function find(string $grantId): ?DigitalDeliveryGrant
    {
        return null;
    }
}
