<?php

declare(strict_types=1);

namespace Commerz\Webhooks\Events;

use Commerz\Webhooks\Data\InboundWebhookPayload;
use Packman\Attributes\Api;

/** Dispatched after commit on the first verified delivery of an inbound webhook (`UNIQUE(source, external_event_id)`). */
#[Api(since: '0.1')]
final readonly class InboundWebhookReceived
{
    public function __construct(public InboundWebhookPayload $event) {}
}
