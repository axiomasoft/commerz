<?php

declare(strict_types=1);

namespace Commerz\Shipping\Methods\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Shipping\Errors\Enums\ShippingErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

/** The method is archived, which is final: it can no longer be changed, enabled or disabled. */
#[Api(since: '0.1')]
final class MethodArchived extends CommerzException
{
    public function __construct(public readonly string $method)
    {
        parent::__construct("Shipping method '{$method}' is archived.");
    }

    public function code(): string
    {
        return 'shipping.method_archived';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['method' => $this->method];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: ShippingErrorCode::MethodArchived,
            category: ErrorCategory::Conflict,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
