<?php

declare(strict_types=1);

namespace Commerz\Ports\Prices\Internal;

use Commerz\Context\CommerceContext;
use Commerz\Ports\Prices\Contracts\PriceResolver;
use Commerz\Ports\Prices\Data\ResolvedPrice;
use Commerz\Ports\Prices\Data\ResolvePrice;
use Commerz\Ports\Prices\Exceptions\PriceNotFound;
use Commerz\Stores\Contracts\Channels;

/** Default without the pricing package: the sellable's base price, as gross or net as the channel sells. */
final readonly class SnapshotPriceResolver implements PriceResolver
{
    public function __construct(private Channels $channels) {}

    public function resolveMany(array $requests, CommerceContext $context): array
    {
        if ($requests === []) {
            return [];
        }

        $taxIncluded = $this->channels->find($context->channel)->pricesIncludeTax;

        return array_map(static function (ResolvePrice $request) use ($context, $taxIncluded): ResolvedPrice {
            $price = $request->fallback;

            if ($price === null || ! $price->currency->equals($context->currency)) {
                throw new PriceNotFound((string) $request->sellable, $context->currency->code);
            }

            return new ResolvedPrice($price, null, $taxIncluded, 'sellable');
        }, $requests);
    }
}
