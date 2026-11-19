<?php

declare(strict_types=1);

namespace Commerz\Outbox\Internal;

use Commerz\Outbox\Contracts\Outbox;
use Commerz\Outbox\Data\IntegrationEvent;
use Commerz\Schema\TableNames;
use Illuminate\Database\ConnectionResolverInterface;
use LogicException;
use Packman\Attributes\Internal;

#[Internal]
final readonly class DatabaseOutbox implements Outbox
{
    public function __construct(
        private TableNames $tables,
        private ConnectionResolverInterface $connections,
    ) {}

    public function record(IntegrationEvent $event): void
    {
        $connection = $this->connections->connection($this->tables->connection());

        if ($connection->transactionLevel() === 0) {
            throw new LogicException("Outbox::record({$event->type}) needs an open transaction on the Commerz connection; use Transactions::run().");
        }

        $connection->table($this->tables->table('outbox_messages'))->insert([
            'event_id' => $event->eventId,
            'type' => $event->type,
            'version' => $event->version,
            'aggregate_ref' => $event->aggregateRef,
            'payload' => json_encode((object) $event->payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            'occurred_at' => $event->occurredAt->format('Y-m-d H:i:s.uP'),
        ]);
    }
}
