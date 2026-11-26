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
final class InvalidWebhookSignature extends CommerzException
{
    public function __construct(
        public readonly string $source,
        public readonly string $reason,
    ) {
        parent::__construct("Webhook signature verification failed for '{$source}': {$reason}.");
    }

    #[Override]
    public function code(): string
    {
        return 'webhooks.invalid_signature';
    }

    #[Override]
    public function category(): ErrorCategory
    {
        return ErrorCategory::Authentication;
    }

    #[Override]
    public function context(): array
    {
        return ['source' => $this->source, 'reason' => $this->reason];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: CommerzErrorCode::InvalidWebhookSignature,
            category: ErrorCategory::Authentication,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
