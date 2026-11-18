<?php

declare(strict_types=1);

namespace Commerz\Sales\Carts\Exceptions;

use Commerz\Errors\InvalidCommand;
use Commerz\Sales\Errors\Enums\SalesErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

/** Delivery is priced or offered for a destination, and the cart has no shipping address yet. */
#[Api(since: '0.1')]
final class ShippingAddressRequired extends InvalidCommand
{
    public function __construct()
    {
        parent::__construct('shippingAddress', 'is required to price or offer delivery', 'sales.shipping_address_required');
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: SalesErrorCode::ShippingAddressRequired,
            category: ErrorCategory::Validation,
            retryable: false,
            details: $this->context(),
        );
    }
}
