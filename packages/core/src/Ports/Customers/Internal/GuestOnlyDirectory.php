<?php

declare(strict_types=1);

namespace Commerz\Ports\Customers\Internal;

use Commerz\Identity\CustomerRef;
use Commerz\Ports\Customers\Contracts\CustomerDirectory;
use Commerz\Ports\Customers\Data\CustomerProfile;
use Packman\Attributes\Internal;

/** Default without `commerz.customers`: every customer is an unknown guest without groups. */
#[Internal]
final readonly class GuestOnlyDirectory implements CustomerDirectory
{
    public function find(CustomerRef $customer): ?CustomerProfile
    {
        return null;
    }
}
