<?php

declare(strict_types=1);

namespace Commerz\Sales\Fulfillments\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;

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
}
