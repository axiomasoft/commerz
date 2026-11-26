<?php

declare(strict_types=1);

namespace Commerz\B2b\BusinessUnits\Data;

use Commerz\B2b\BusinessUnits\Enums\BusinessUnitStatus;
use Commerz\Errors\InvalidCommand;
use Commerz\Ports\Delivery\Data\Address;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class UpdateBusinessUnit
{
    public ?string $name;

    public function __construct(
        public BusinessUnitId $id,
        ?string $name = null,
        public ?BusinessUnitStatus $status = null,
        public ?string $taxId = null,
        public ?string $defaultPaymentTermsCode = null,
        public ?Address $billingAddress = null,
        public ?Address $shippingAddress = null,
    ) {
        if ($name !== null) {
            $name = trim($name);
            if ($name === '' || strlen($name) > 191) {
                throw new InvalidCommand('name', 'must be 1..191 bytes', 'b2b.invalid_business_unit');
            }
        }

        $this->name = $name;
    }
}
