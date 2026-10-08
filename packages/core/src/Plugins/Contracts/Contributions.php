<?php

declare(strict_types=1);

namespace Commerz\Plugins\Contracts;

use Commerz\Attributes\Api;
use Commerz\Errors\UnsupportedContribution;

/**
 * The compiled, immutable plugin registry. It is built lazily on first use and never changes for the rest of the
 * process, so it is safe in long-running workers. Contributions are shared, stateless instances.
 */
#[Api(since: '0.1')]
interface Contributions
{
    /**
     * @template T of object
     *
     * @param  class-string<T>  $interface  the contract the caller expects; a contribution of another type is unsupported
     * @return T
     *
     * @throws UnsupportedContribution unknown kind, key not contributed, or wrong contract
     */
    public function get(string $kind, string $key, string $interface): object;

    public function has(string $kind, string $key): bool;

    /**
     * Keys in plugin load order (dependencies first), then registration order. Empty for a kind nobody contributed to.
     *
     * @return list<string>
     */
    public function keys(string $kind): array;
}
