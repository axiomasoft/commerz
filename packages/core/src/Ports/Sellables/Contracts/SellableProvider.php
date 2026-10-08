<?php

declare(strict_types=1);

namespace Commerz\Ports\Sellables\Contracts;

use Commerz\Attributes\Spi;
use Commerz\Attributes\SpiKind;
use Commerz\Context\CommerceContext;
use Commerz\Identity\SellableRef;
use Commerz\Ports\Sellables\Data\SellableSnapshot;

/**
 * Source of sellables of one ref type: the catalog provides `variant`, a CRM its own services. Registered as the
 * `sellable_provider` contribution (`PluginRegistrar::sellableProvider()`); `key()` is the ref type it serves.
 * Reads only: no writes, no network calls inside a database transaction.
 */
#[Spi(kind: SpiKind::Implement, since: '0.1')]
interface SellableProvider
{
    public const string KIND = 'sellable_provider';

    /** The `SellableRef` type served, e.g. `variant` or `crm.service`. */
    public function key(): string;

    /**
     * Unknown refs are left out of the result; the router reports them as unavailable.
     *
     * @param  list<SellableRef>  $refs  all of type `key()`, without duplicates
     * @return array<string, SellableSnapshot> keyed by `(string) $ref`
     */
    public function resolve(array $refs, CommerceContext $context): array;
}
