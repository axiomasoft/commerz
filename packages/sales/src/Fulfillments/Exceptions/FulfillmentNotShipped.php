<?php

declare(strict_types=1);

namespace Commerz\Sales\Fulfillments\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;
use Commerz\Sales\Errors\Enums\SalesErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory as PackmanErrorCategory;

/** Only a shipped fulfillment can be delivered. */
#[Api(since: '0.1')]
final class FulfillmentNotShipped extends CommerzException
{
    public function __construct(public readonly string $fulfillment)
    {
        parent::__construct("Fulfillment '{$fulfillment}' is not shipped yet.");
    }

    public function code(): string
    {
        return 'sales.fulfillment_not_shipped';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['fulfillment' => $this->fulfillment];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: SalesErrorCode::FulfillmentNotShipped,
            category: PackmanErrorCategory::Conflict,
            retryable: false,
            details: $this->context(),
        );
    }
}
