<?php

declare(strict_types=1);

namespace Commerz\Outbox\Contracts;

use Commerz\Attributes\Api;
use Commerz\Outbox\Data\IntegrationEvent;

/**
 * Transactional outbox: an event is stored in the same transaction as the change it describes and delivered by
 * `commerz:outbox:relay` after commit, at least once.
 */
#[Api(since: '0.1')]
interface Outbox
{
    /**
     * @throws \LogicException when no transaction is open on the Commerz connection
     * @throws \JsonException when the payload is not JSON-encodable
     */
    public function record(IntegrationEvent $event): void;
}
