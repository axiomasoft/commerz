<?php

declare(strict_types=1);

namespace Commerz\Operations\Contracts;

use Commerz\Errors\IdempotencyConflict;
use Commerz\Identity\IdempotencyKey;
use Packman\Attributes\Api;

/**
 * Idempotent execution (INV-01): a keyed operation runs at most once per `(scope, key)`.
 *
 * Inside one transaction (joined or opened through `Transactions`): the first caller claims the key and runs `$work`;
 * a concurrent caller with the same key waits for that transaction. A later call with the same fingerprint returns
 * the stored result through `$decode`; a different fingerprint throws `IdempotencyConflict`. When `$work` throws,
 * the claim rolls back with the transaction, so a retry runs the work again.
 */
#[Api(since: '0.1')]
interface OperationReceipts
{
    /**
     * @template T
     *
     * @param  string  $scope  operation name, `[a-z][a-z0-9_.]{0,63}`, e.g. `sales.place_order`
     * @param  string  $fingerprint  64 lower-case hex digits of the request payload (see `Fingerprint::of()`)
     * @param  callable(): T  $work
     * @param  callable(T): array<array-key, mixed>  $encode  JSON-encodable result to store
     * @param  callable(array<array-key, mixed>): T  $decode
     * @return T
     *
     * @throws IdempotencyConflict
     */
    public function once(string $scope, IdempotencyKey $key, string $fingerprint, callable $work, callable $encode, callable $decode): mixed;
}
