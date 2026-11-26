<?php

declare(strict_types=1);

namespace Commerz\Webhooks\Exceptions;

use Commerz\Errors\Enums\CommerzErrorCode;
use Commerz\Errors\NotFound;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

#[Api(since: '0.1')]
final class WebhookDeliveryNotFound extends NotFound
{
    public function __construct(string $id)
    {
        parent::__construct('WebhookDelivery', $id, 'webhooks.delivery_not_found');
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: CommerzErrorCode::WebhookDeliveryNotFound,
            category: ErrorCategory::NotFound,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
