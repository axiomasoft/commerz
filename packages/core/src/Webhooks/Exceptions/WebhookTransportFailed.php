<?php

declare(strict_types=1);

namespace Commerz\Webhooks\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Errors\Enums\CommerzErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Throwable;

#[Api(since: '0.1')]
final class WebhookTransportFailed extends CommerzException
{
    public function __construct(
        public readonly string $url,
        public readonly string $reason,
        public readonly ?int $statusCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct("Webhook delivery to '{$url}' failed: {$reason}.", previous: $previous);
    }

    #[Override]
    public function code(): string
    {
        return 'webhooks.transport_failed';
    }

    #[Override]
    public function category(): ErrorCategory
    {
        return ErrorCategory::Unavailable;
    }

    #[Override]
    public function context(): array
    {
        return [
            'url' => $this->url,
            'reason' => $this->reason,
            'status_code' => $this->statusCode,
        ];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: CommerzErrorCode::WebhookTransportFailed,
            category: ErrorCategory::Unavailable,
            retryable: true,
            details: $this->descriptorDetails(),
        );
    }
}
