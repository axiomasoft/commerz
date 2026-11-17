<?php

declare(strict_types=1);

namespace Commerz\Operations\Internal\Console;

use Commerz\Configuration\CommerzOptions;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Packman\Attributes\Internal;
use Packman\Schema\Data\Compiler\TableNames;

/** Removes idempotency receipts and delivered outbox messages older than `commerz.operations.receipt_ttl_days`. */
#[Description('Delete expired Commerz idempotency receipts and delivered outbox messages.')]
#[Internal]
#[Signature('commerz:operations:prune')]
final class PruneOperationsCommand extends Command
{
    private const int CHUNK = 5000;

    public function handle(CommerzOptions $options, TableNames $tables, ConnectionResolverInterface $connections): int
    {
        $connection = $connections->connection($tables->connection());
        $days = $options->receiptTtlDays;

        $receipts = $this->prune($connection, $tables->table('operation_receipts'), 'created_at', $days);
        $messages = $this->prune($connection, $tables->table('outbox_messages'), 'delivered_at', $days);

        $this->components->info("Pruned {$receipts} receipts and {$messages} delivered outbox messages older than {$days} days.");

        return self::SUCCESS;
    }

    /** Deletes in chunks so a large backlog never holds one long lock. */
    private function prune(ConnectionInterface $connection, string $table, string $column, int $days): int
    {
        $total = 0;

        do {
            $deleted = $connection->delete(
                "delete from {$table} where ctid in (select ctid from {$table} where {$column} < now() - make_interval(days => ?) limit ?)",
                [$days, self::CHUNK],
            );
            $total += $deleted;
        } while ($deleted === self::CHUNK);

        return $total;
    }
}
