<?php

declare(strict_types=1);

namespace Commerz\Webhooks\Data;

use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class OutboundWebhookRequest
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public WebhookDeliveryId $deliveryId,
        public string $url,
        public string $body,
        public array $headers,
        public int $timeoutSeconds = 10,
    ) {
        CreateWebhookSubscription::assertTargetUrl($url);

        if ($timeoutSeconds < 1 || $timeoutSeconds > 120) {
            throw new InvalidCommand('timeoutSeconds', 'must be from 1 to 120', 'webhooks.invalid_request');
        }
    }
}
