<?php

declare(strict_types=1);

namespace Commerz\Shipping\Packing\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Shipping\Errors\Enums\ShippingErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

/** Thrown when a single unit exceeds `PackingConstraints` and cannot fit in an empty parcel. */
#[Api(since: '0.1')]
final class UnpackableItem extends CommerzException
{
    public function __construct(
        public readonly string $item,
        public readonly string $reason,
    ) {
        parent::__construct("Item '{$item}' cannot be packed: {$reason}");
    }

    public function code(): string
    {
        return 'shipping.unpackable_item';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Validation;
    }

    public function context(): array
    {
        return [
            'item' => $this->item,
            'reason' => $this->reason,
        ];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: ShippingErrorCode::UnpackableItem,
            category: ErrorCategory::Validation,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
