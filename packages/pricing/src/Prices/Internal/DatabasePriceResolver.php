<?php

declare(strict_types=1);

namespace Commerz\Pricing\Prices\Internal;

use Commerz\Context\CommerceContext;
use Commerz\Money\Money;
use Commerz\Ports\Prices\Contracts\PriceResolver;
use Commerz\Ports\Prices\Data\ResolvedPrice;
use Commerz\Ports\Prices\Data\ResolvePrice;
use Commerz\Ports\Prices\Exceptions\PriceNotFound;
use Commerz\Pricing\Common\Internal\PricingConfig;
use Commerz\Pricing\Prices\Data\ExplainPrice;
use Commerz\Pricing\Prices\Data\PriceCandidate;
use Commerz\Pricing\Prices\Data\PriceExplanation;
use Commerz\Pricing\Prices\Data\PriceId;
use Commerz\Stores\Contracts\Channels;

/**
 * The `PriceResolver` of the price lists (PRC-02). One read of the candidates serves the whole call; `explain` runs
 * the same candidates and selection, so it cannot disagree with `resolveMany`. Reads only, no locks; time is the context's.
 */
final readonly class DatabasePriceResolver implements PriceResolver
{
    public function __construct(
        private PriceCandidates $candidates,
        private Channels $channels,
        private PricingConfig $config,
    ) {}

    public function resolveMany(array $requests, CommerceContext $context): array
    {
        $prices = [];

        foreach ($this->decide($requests, $context) as $index => [$price]) {
            $prices[] = $price ?? throw new PriceNotFound((string) $requests[$index]->sellable, $context->currency->code);
        }

        return $prices;
    }

    public function explain(ExplainPrice $query): PriceExplanation
    {
        $context = $query->context;
        [[$price, $fallbackUsed, $ranked]] = $this->decide([new ResolvePrice($query->sellable, $query->quantity, $query->fallback)], $context);

        return new PriceExplanation($price, array_map(
            static fn (RankedPrice $entry): PriceCandidate => new PriceCandidate(
                new PriceId($entry->price->id),
                $entry->price->priceList,
                $entry->price->priority,
                $entry->price->customerGroup,
                $entry->price->minQuantity,
                Money::ofMinor($entry->price->amountMinor, $context->currency),
                $entry->price->compareAtMinor === null ? null : Money::ofMinor($entry->price->compareAtMinor, $context->currency),
                $entry->price->validFrom,
                $entry->price->validUntil,
                $entry->verdict,
            ),
            $ranked,
        ), $fallbackUsed);
    }

    /**
     * @param  list<ResolvePrice>  $requests
     * @return list<array{ResolvedPrice|null, bool, list<RankedPrice>}> per request: the price, whether it is the fallback, the ranking
     */
    private function decide(array $requests, CommerceContext $context): array
    {
        if ($requests === []) {
            return [];
        }

        $listed = $this->candidates->load(
            array_map(static fn (ResolvePrice $request): string => (string) $request->sellable, $requests),
            $context->channel,
            $context->currency,
        );
        $pricesIncludeTax = null;
        $channelTax = function () use (&$pricesIncludeTax, $context): bool {
            return $pricesIncludeTax ??= $this->channels->find($context->channel)->pricesIncludeTax;
        };

        $decisions = [];

        foreach ($requests as $request) {
            $ranked = PriceSelection::rank($listed, (string) $request->sellable, $request->quantity, $context->customerGroups, $context->at);
            $winner = PriceSelection::winner($ranked);

            if ($winner instanceof ListedPrice) {
                $decisions[] = [new ResolvedPrice(
                    Money::ofMinor($winner->amountMinor, $context->currency),
                    $winner->compareAtMinor === null ? null : Money::ofMinor($winner->compareAtMinor, $context->currency),
                    $winner->taxIncluded ?? $channelTax(),
                    'price_list:'.$winner->priceList,
                ), false, $ranked];

                continue;
            }

            $fallback = $request->fallback;

            if ($this->config->fallbackToSellable && $fallback instanceof Money && $fallback->currency->equals($context->currency)) {
                $decisions[] = [new ResolvedPrice($fallback, null, $channelTax(), 'sellable'), true, $ranked];

                continue;
            }

            $decisions[] = [null, false, $ranked];
        }

        return $decisions;
    }
}
