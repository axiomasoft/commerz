<?php

declare(strict_types=1);

namespace Commerz\Ports\Customers\Contracts;

use Commerz\Attributes\Spi;
use Commerz\Attributes\SpiKind;
use Commerz\Identity\CustomerRef;
use Commerz\Ports\Customers\Data\CustomerProfile;

/** The host's customers, configured as `commerz.customers`. Reads only. */
#[Spi(kind: SpiKind::Implement, since: '0.1')]
interface CustomerDirectory
{
    /** null for an unknown customer, who is then treated as a guest */
    public function find(CustomerRef $customer): ?CustomerProfile;
}
