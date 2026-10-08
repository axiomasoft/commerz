<?php

declare(strict_types=1);

namespace Commerz\Stores\Internal;

use Commerz\Configuration\CommerzConfig;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;

/** Dispatches Laravel events once the surrounding transaction (ours or the host's) has committed. */
final readonly class CommittedEvents
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzConfig $config,
        private Dispatcher $events,
    ) {}

    public function dispatch(object $event): void
    {
        $connection = $this->connections->connection($this->config->connection);

        if ($connection instanceof Connection) {
            $connection->afterCommit(fn () => $this->events->dispatch($event));

            return;
        }

        $this->events->dispatch($event);
    }
}
