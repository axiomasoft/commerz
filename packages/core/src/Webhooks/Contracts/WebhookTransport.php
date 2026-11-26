<?php

declare(strict_types=1);

namespace Commerz\Webhooks\Contracts;

use Commerz\Webhooks\Data\OutboundWebhookRequest;
use Commerz\Webhooks\Data\WebhookTransportResponse;
use Commerz\Webhooks\Exceptions\WebhookTransportFailed;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Outgoing HTTP transport port for signed webhook deliveries. Runs strictly outside any database transaction.
 */
#[Spi(kind: SpiKind::Implement, since: '0.1')]
interface WebhookTransport
{
    /**
     * @throws WebhookTransportFailed on network, DNS, TLS, or timeout failure
     */
    public function send(OutboundWebhookRequest $request): WebhookTransportResponse;
}
