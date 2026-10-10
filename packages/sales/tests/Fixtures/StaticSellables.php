<?php

declare(strict_types=1);

namespace Commerz\Sales\Tests\Fixtures;

use Commerz\Context\CommerceContext;
use Commerz\Identity\SellableRef;
use Commerz\Money\Money;
use Commerz\Ports\Sellables\Contracts\SellableProvider;
use Commerz\Ports\Sellables\Data\SellableSnapshot;
use Commerz\Ports\Sellables\Enums\SellableKind;

/**
 * Sellables of ref type `test` from a static registry: defined in the test process, they are visible to the processes
 * `Concurrency::run` forks, which in-memory fakes bound to the container are not.
 */
final class StaticSellables implements SellableProvider
{
    /** @var array<string, SellableSnapshot> */
    private static array $snapshots = [];

    public static function define(SellableSnapshot ...$snapshots): void
    {
        foreach ($snapshots as $snapshot) {
            self::$snapshots[(string) $snapshot->ref] = $snapshot;
        }
    }

    /** A purchasable `test:{$id}` at `$price` RUB. */
    public static function item(string $id, string $price = '100.00', SellableKind $kind = SellableKind::Physical, ?int $grams = null): SellableSnapshot
    {
        return new SellableSnapshot(
            SellableRef::of('test', $id),
            strtoupper($id),
            "Item {$id}",
            $kind,
            $kind === SellableKind::Physical,
            basePrice: Money::of($price, 'RUB'),
            weightGrams: $grams,
        );
    }

    public static function reset(): void
    {
        self::$snapshots = [];
    }

    public function key(): string
    {
        return 'test';
    }

    public function resolve(array $refs, CommerceContext $context): array
    {
        $found = [];

        foreach ($refs as $ref) {
            if (isset(self::$snapshots[(string) $ref])) {
                $found[(string) $ref] = self::$snapshots[(string) $ref];
            }
        }

        return $found;
    }
}
