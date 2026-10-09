<?php

declare(strict_types=1);

namespace Commerz\Operations\Contracts;

use Commerz\Attributes\Api;

/**
 * Database transactions on the Commerz connection (`commerz.connection`).
 *
 * `run()` opens a transaction and sets `lock_timeout` for it; when a transaction is already open on that connection
 * (the host's or an outer `run()`), the work runs in a savepoint and the outer settings apply. A failed nested
 * operation rolls back its own writes and pending events even if the host catches the exception. There is no retry: on
 * a lock timeout or deadlock the caller decides whether to repeat the whole operation.
 */
#[Api(since: '0.1')]
interface Transactions
{
    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @param  int  $lockTimeoutMs  `lock_timeout` of a new transaction; 0 waits without limit
     * @return T
     */
    public function run(callable $work, int $lockTimeoutMs = 3000): mixed;

    public function inTransaction(): bool;
}
