<?php

declare(strict_types=1);

namespace Commerz\B2b\BusinessUnits\Data;

use Commerz\Identity\CustomerRef;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class RemoveAssociate
{
    public function __construct(
        public BusinessUnitId $businessUnitId,
        public CustomerRef $customer,
    ) {}
}
