<?php

declare(strict_types=1);

namespace Commerz\Webhooks\Contracts;

use Commerz\Webhooks\Data\InboundWebhookPayload;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Processes a verified, deduplicated inbound webhook inside the gateway's deduplication transaction. */
#[Spi(kind: SpiKind::Implement, since: '0.1')]
interface InboundWebhookHandler
{
    public function handle(InboundWebhookPayload $event): void;
}
