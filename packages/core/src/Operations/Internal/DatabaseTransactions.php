<?php

declare(strict_types=1);

namespace Commerz\Operations\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\Operations\Contracts\Transactions;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use Packman\Attributes\Internal;
use Throwable;

#[Internal]
final readonly class DatabaseTransactions implements Transactions
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $options,
    ) {}

    public function run(callable $work, int $lockTimeoutMs = 3000): mixed
    {
        if ($lockTimeoutMs < 0) {
            throw new InvalidArgumentException('lockTimeoutMs must be 0 or greater.');
        }

        $connection = $this->connection();

        if ($connection->transactionLevel() > 0) {
            $level = $connection->transactionLevel();
            $connection->beginTransaction();

            try {
                $result = $work();
                $connection->commit();

                return $result;
            } catch (Throwable $error) {
                // Laravel's nested deadlock path decrements its level without rolling back the PostgreSQL savepoint.
                while ($connection->transactionLevel() > $level) {
                    $connection->rollBack();
                }

                throw $error;
            }
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
        return $this->connections->connection($this->options->connection);
    }
}
