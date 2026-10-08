<?php

declare(strict_types=1);

namespace Commerz\Ports\Sellables\Contracts;

use Commerz\Attributes\Api;
use Commerz\Context\CommerceContext;
use Commerz\Errors\UnsupportedContribution;
use Commerz\Identity\SellableRef;
use Commerz\Ports\Sellables\Data\SellableSnapshot;
use Commerz\Ports\Sellables\Exceptions\SellableUnavailable;

/**
 * Resolves anything sellable to a snapshot by routing `SellableRef::$type` to the `SellableProvider` with that key.
 * A snapshot that is not `purchasable` is still returned; callers decide whether to sell it.
 */
#[Api(since: '0.1')]
interface Sellables
{
    /**
     * @throws UnsupportedContribution no provider is registered for the ref type
     * @throws SellableUnavailable the provider does not know the ref
     */
    public function resolve(SellableRef $ref, CommerceContext $context): SellableSnapshot;

    /**
     * Duplicate refs are resolved once.
     *
     * @param  list<SellableRef>  $refs
     * @return array<string, SellableSnapshot> keyed by `(string) $ref`, in the order of `$refs`
     *
     * @throws UnsupportedContribution no provider is registered for a ref type
     * @throws SellableUnavailable a provider does not know one of the refs
     */
    public function resolveMany(array $refs, CommerceContext $context): array;
}
