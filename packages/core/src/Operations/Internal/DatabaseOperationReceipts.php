<?php

declare(strict_types=1);

namespace Commerz\Operations\Internal;

use Commerz\Errors\IdempotencyConflict;
use Commerz\Identity\IdempotencyKey;
use Commerz\Operations\Contracts\OperationReceipts;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Schema\TableNames;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use LogicException;

/**
 * `INSERT … ON CONFLICT DO NOTHING RETURNING` claims the key; a concurrent claimant blocks on the unique index until
 * the first transaction ends, then reads the committed receipt with `SELECT … FOR UPDATE`. No read-then-write.
 */
final readonly class DatabaseOperationReceipts implements OperationReceipts
{
    public function __construct(
        private Transactions $transactions,
        private TableNames $tables,
        private ConnectionResolverInterface $connections,
    ) {}

    public function once(string $scope, IdempotencyKey $key, string $fingerprint, callable $work, callable $encode, callable $decode): mixed
    {
        if (preg_match('/\A[a-z][a-z0-9_.]{0,63}\z/', $scope) !== 1) {
            throw new InvalidArgumentException("Receipt scope '{$scope}' must match [a-z][a-z0-9_.]{0,63}.");
        }

        if (preg_match('/\A[0-9a-f]{64}\z/', $fingerprint) !== 1) {
            throw new InvalidArgumentException('A receipt fingerprint is 64 lower-case hex digits (sha256).');
        }

        return $this->transactions->run(function () use ($scope, $key, $fingerprint, $work, $encode, $decode): mixed {
            $connection = $this->connections->connection($this->tables->connection());
            $table = $this->tables->table('operation_receipts');

            // Two rounds at most: the second covers a receipt pruned between the claim attempt and the read.
            for ($round = 1; $round <= 2; $round++) {
                $claimed = $connection->selectOne(
                    "insert into {$table} (scope, key, fingerprint) values (?, ?, ?) on conflict (scope, key) do nothing returning scope",
                    [$scope, $key->value, $fingerprint],
                    useReadPdo: false,
                );

                if ($claimed !== null) {
                    $result = $work();

                    $connection->update(
                        "update {$table} set result = ?::jsonb where scope = ? and key = ?",
                        [json_encode($encode($result), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), $scope, $key->value],
                    );

                    return $result;
                }

                $receipt = $connection->selectOne(
                    "select fingerprint, result::text as result from {$table} where scope = ? and key = ? for update",
                    [$scope, $key->value],
                    useReadPdo: false,
                );

                if (! is_object($receipt)) {
                    continue;
                }

                if (! property_exists($receipt, 'fingerprint') || $receipt->fingerprint !== $fingerprint) {
                    throw new IdempotencyConflict($scope, $key->value);
                }

                $stored = property_exists($receipt, 'result') ? $receipt->result : null;

                if (! is_string($stored)) {
                    throw new LogicException("Operation '{$scope}' with key '{$key->value}' re-entered itself before storing a result.");
                }

                $decoded = json_decode($stored, true, 512, JSON_THROW_ON_ERROR);

                return $decode(is_array($decoded) ? $decoded : []);
            }

            throw new LogicException("Receipt '{$scope}/{$key->value}' disappeared twice while being read.");
        });
    }
}
