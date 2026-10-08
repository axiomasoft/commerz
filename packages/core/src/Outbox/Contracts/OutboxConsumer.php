<?php

declare(strict_types=1);

namespace Commerz\Outbox\Contracts;

use Commerz\Attributes\Spi;
use Commerz\Attributes\SpiKind;
use Commerz\Outbox\Data\IntegrationEvent;

/**
 * Receives every relayed event (contribution kind `outbox_consumer`; until the plugin registry exists, a container
 * binding tagged `commerz.outbox_consumers`). Delivery is at least once and in no guaranteed order across retries:
 * deduplicate by `eventId` and ignore types you do not handle. Throwing schedules the event for a later retry,
 * which reaches every consumer again. Runs outside the relay's database transaction.
 */
#[Spi(kind: SpiKind::Implement, since: '0.1')]
interface OutboxConsumer
{
    /** Stable key, `vendor.name`. */
    public function key(): string;

    public function consume(IntegrationEvent $event): void;
}
