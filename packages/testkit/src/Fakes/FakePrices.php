<?php

declare(strict_types=1);

namespace Commerz\Testkit\Fakes;

use Commerz\Context\CommerceContext;
use Commerz\Identity\SellableRef;
use Commerz\Money\Money;
use Commerz\Ports\Prices\Contracts\PriceResolver;
use Commerz\Ports\Prices\Data\ResolvedPrice;
use Commerz\Ports\Prices\Data\ResolvePrice;
use Commerz\Ports\Prices\Exceptions\PriceNotFound;

/** Fixed unit prices per sellable; otherwise the request's fallback (the sellable's base price), as the core does. */
final class FakePrices implements PriceResolver
{
    /** @var array<string, list<array{Money, Money|null}>> ref => [unit, compareAt] per currency */
    private array $prices = [];

    /** @var list<ResolvePrice> every request seen, in order */
    public private(set) array $requests = [];

    public function __construct(public bool $taxIncluded = false) {}

    public function set(SellableRef $sellable, Money $unit, ?Money $compareAt = null): self
    {
        $existing = array_filter(
            $this->prices[(string) $sellable] ?? [],
            static fn (array $price): bool => ! $price[0]->currency->equals($unit->currency),
        );
        $this->prices[(string) $sellable] = [...$existing, [$unit, $compareAt]];

        return $this;
    }

    public function resolveMany(array $requests, CommerceContext $context): array
    {
        return array_map(function (ResolvePrice $request) use ($context): ResolvedPrice {
            $this->requests[] = $request;

            foreach ($this->prices[(string) $request->sellable] ?? [] as [$unit, $compareAt]) {
                if ($unit->currency->equals($context->currency)) {
                    return new ResolvedPrice($unit, $compareAt, $this->taxIncluded, 'fake');
                }
            }

            if ($request->fallback !== null && $request->fallback->currency->equals($context->currency)) {
                return new ResolvedPrice($request->fallback, null, $this->taxIncluded, 'sellable');
            }

            throw new PriceNotFound((string) $request->sellable, $context->currency->code);
        }, $requests);
    }
}
