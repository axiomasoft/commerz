<?php

declare(strict_types=1);

namespace Commerz\Outbox\Events;

use Commerz\Attributes\Api;
use Commerz\Outbox\Data\IntegrationEvent;

/** Laravel event dispatched by the outbox relay for each delivered event; same guarantees as `OutboxConsumer`. */
#[Api(since: '0.1')]
final readonly class IntegrationEventDelivered
{
    public function __construct(public IntegrationEvent $event) {}
}
