<?php

declare(strict_types=1);

namespace Commerz\Webhooks\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Errors\Enums\CommerzErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

#[Api(since: '0.1')]
final class DuplicateWebhookSubscription extends CommerzException
{
    public function __construct(public readonly string $subscriptionCode)
    {
        parent::__construct("Webhook subscription code '{$subscriptionCode}' is already taken.");
    }

    #[Override]
    public function code(): string
    {
        return 'webhooks.duplicate_subscription';
    }

    #[Override]
    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    #[Override]
    public function context(): array
    {
        return ['code' => $this->subscriptionCode];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: CommerzErrorCode::DuplicateWebhookSubscription,
            category: ErrorCategory::Conflict,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
