<?php

declare(strict_types=1);

namespace Commerz\Testkit\Fakes;

use Commerz\Context\CommerceContext;
use Commerz\Identity\SellableRef;
use Commerz\Money\Money;
use Commerz\Ports\Sellables\Contracts\SellableProvider;
use Commerz\Ports\Sellables\Data\SellableSnapshot;
use Commerz\Ports\Sellables\Enums\SellableKind;
use Packman\Attributes\Api;

/**
 * In-memory sellables. As a `SellableProvider` (contribute it from a test plugin) it serves refs of type `$key`;
 * behind `Commerce::fake()` it answers every snapshot it holds, of any type.
 */
#[Api(since: '0.1')]
final class FakeSellables implements SellableProvider
{
    /** @var array<string, SellableSnapshot> */
    private array $snapshots = [];

    public function __construct(private readonly string $key = 'fake') {}

    /** A purchasable snapshot with sensible defaults; `$ref` is `type:id` or a bare id of type `fake`. */
    public static function snapshot(
        SellableRef|string $ref,
        ?Money $basePrice = null,
        SellableKind $kind = SellableKind::Physical,
        ?string $name = null,
    ): SellableSnapshot {
        $ref = is_string($ref) ? (str_contains($ref, ':') ? SellableRef::fromString($ref) : SellableRef::of('fake', $ref)) : $ref;

        return new SellableSnapshot(
            ref: $ref,
            sku: strtoupper($ref->id),
            name: $name ?? "Sellable {$ref->id}",
            kind: $kind,
            requiresShipping: $kind === SellableKind::Physical,
            basePrice: $basePrice,
        );
    }

    public function add(SellableSnapshot ...$snapshots): self
    {
        foreach ($snapshots as $snapshot) {
            $this->snapshots[(string) $snapshot->ref] = $snapshot;
        }

        return $this;
    }

    public function forget(SellableRef $ref): self
    {
        unset($this->snapshots[(string) $ref]);

        return $this;
    }

    public function find(SellableRef $ref): ?SellableSnapshot
    {
        return $this->snapshots[(string) $ref] ?? null;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function resolve(array $refs, CommerceContext $context): array
    {
        $found = [];

        foreach ($refs as $ref) {
            $snapshot = $this->find($ref);

            if ($snapshot !== null) {
                $found[(string) $ref] = $snapshot;
            }
        }

        return $found;
    }
}
