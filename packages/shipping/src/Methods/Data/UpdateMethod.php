<?php

declare(strict_types=1);

namespace Commerz\Shipping\Methods\Data;

use Commerz\Measures\LocalizedText;
use Commerz\Ports\Delivery\Enums\DeliveryMode;
use Commerz\Shipping\Common\Data\ShippingCodes;
use Packman\Attributes\Api;

/** Changes the given fields; a null field stays as it is. */
#[Api(since: '0.1')]
final readonly class UpdateMethod
{
    public function __construct(
        public MethodId $id,
        public ?LocalizedText $name = null,
        public ?string $carrier = null,
        public ?int $position = null,
        public ?DeliveryMode $mode = null,
    ) {
        if ($name instanceof LocalizedText) {
            CreateMethod::assertName($name);
        }

        if ($carrier !== null) {
            ShippingCodes::carrierKey('carrier', $carrier);
        }

        if ($position !== null) {
            CreateMethod::assertPosition($position);
        }
    }
}
