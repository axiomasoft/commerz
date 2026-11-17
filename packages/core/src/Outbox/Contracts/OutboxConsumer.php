<?php

declare(strict_types=1);

namespace Commerz\Outbox\Contracts;

use Commerz\Outbox\Data\IntegrationEvent;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Receives every relayed event. Registered by a plugin: `$registrar->contribute(OutboxConsumer::KIND, MyConsumer::class)`.
 * Delivery is at least once and in no guaranteed order across retries:
 * deduplicate by `eventId` and ignore types you do not handle. Throwing schedules the event for a later retry,
 * which reaches every consumer again. Runs outside the relay's database transaction.
 */
#[Spi(kind: SpiKind::Implement, since: '0.1')]
interface OutboxConsumer
{
    /** Contribution kind, declared by the core plugin. */
    public const string KIND = 'outbox_consumer';

    /** Stable key, `vendor.name`. */
    public function key(): string;

    public function consume(IntegrationEvent $event): void;
}
