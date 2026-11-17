<?php

declare(strict_types=1);

namespace Commerz\Operations\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\Operations\Contracts\CommittedEvents;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Packman\Attributes\Internal;

#[Internal]
final readonly class ConnectionCommittedEvents implements CommittedEvents
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $options,
        private Container $container,
    ) {}

    public function dispatch(object $event): void
    {
        $connection = $this->connections->connection($this->options->connection);

        if ($connection instanceof Connection) {
            $connection->afterCommit(fn () => $this->dispatcher()->dispatch($event));

            return;
        }

        $this->dispatcher()->dispatch($event);
    }

    /** Resolved per event, so a dispatcher swapped after this singleton was built (`Event::fake()`) still receives it. */
    private function dispatcher(): Dispatcher
    {
        return $this->container->make(Dispatcher::class);
    }
}
