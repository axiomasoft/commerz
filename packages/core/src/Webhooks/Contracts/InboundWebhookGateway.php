<?php

declare(strict_types=1);

namespace Commerz\Webhooks\Contracts;

use Commerz\Errors\IdempotencyConflict;
use Commerz\Errors\InvalidCommand;
use Commerz\Errors\NotFound;
use Commerz\Webhooks\Data\InboundWebhookEventId;
use Commerz\Webhooks\Data\InboundWebhookPayload;
use Commerz\Webhooks\Data\InboundWebhookResult;
use Commerz\Webhooks\Data\InboundWebhookSource;
use Commerz\Webhooks\Data\ReceiveInboundWebhook;
use Commerz\Webhooks\Exceptions\InvalidWebhookSignature;
use Packman\Attributes\Api;

/**
 * Inbound webhook gateway: verifies HMAC-SHA256 signatures in constant time and deduplicates deliveries
 * atomically by `(source, external_event_id)`.
 */
#[Api(since: '0.1')]
interface InboundWebhookGateway
{
    public function registerSource(InboundWebhookSource $source, ?InboundWebhookHandler $handler = null): void;

    /**
     * Verifies `$webhook->signature` against the configured or supplied `$source` and processes the event at most once
     * per `(source, external_event_id)`.
     *
     * @throws InvalidWebhookSignature when HMAC-SHA256 signature or replay window verification fails
     * @throws IdempotencyConflict when `(source, external_event_id)` was already received with a different body
     * @throws InvalidCommand when the source is unknown or the body is not a JSON object
     */
    public function receive(
        ReceiveInboundWebhook $webhook,
        ?InboundWebhookSource $source = null,
        ?InboundWebhookHandler $handler = null,
    ): InboundWebhookResult;

    /** @throws NotFound */
    public function find(InboundWebhookEventId $id): InboundWebhookPayload;
}
