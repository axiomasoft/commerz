<?php

declare(strict_types=1);

namespace Commerz\Operations\Internal;

use Commerz\Configuration\CommerzConfig;
use Commerz\Operations\Contracts\Transactions;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;

final readonly class DatabaseTransactions implements Transactions
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzConfig $config,
    ) {}

    public function run(callable $work, int $lockTimeoutMs = 3000): mixed
    {
        if ($lockTimeoutMs < 0) {
            throw new InvalidArgumentException('lockTimeoutMs must be 0 or greater.');
        }

        $connection = $this->connection();

        if ($connection->transactionLevel() > 0) {
            return $work();
        }

        return $connection->transaction(static function () use ($connection, $work, $lockTimeoutMs): mixed {
            $connection->statement("set local lock_timeout = '{$lockTimeoutMs}ms'");

            return $work();
        });
    }

    public function inTransaction(): bool
    {
        return $this->connection()->transactionLevel() > 0;
    }

    private function connection(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}
