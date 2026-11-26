<?php

declare(strict_types=1);

namespace Commerz\Webhooks\Data;

use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class WebhookTransportResponse
{
    public function __construct(
        public int $statusCode,
        public string $body = '',
    ) {
        if ($statusCode < 100 || $statusCode > 599) {
            throw new InvalidCommand('statusCode', 'must be an HTTP status code from 100 to 599', 'webhooks.invalid_response');
        }
    }

    public function isSuccessful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }
}
