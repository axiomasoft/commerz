<?php

declare(strict_types=1);

namespace Commerz\Pricing\Prices\Internal;

use Commerz\Context\CommerceContext;
use Commerz\Money\Money;
use Commerz\Ports\Prices\Contracts\PriceResolver;
use Commerz\Ports\Prices\Data\ResolvedPrice;
use Commerz\Ports\Prices\Data\ResolvePrice;
use Commerz\Ports\Prices\Exceptions\PriceNotFound;
use Commerz\Pricing\Configuration\PricingOptions;
use Commerz\Pricing\Prices\Data\ExplainPrice;
use Commerz\Pricing\Prices\Data\PriceCandidate;
use Commerz\Pricing\Prices\Data\PriceExplanation;
use Commerz\Pricing\Prices\Data\PriceId;
use Commerz\Stores\Contracts\Channels;
use Packman\Attributes\Internal;

/**
 * The `PriceResolver` of the price lists (PRC-02). One read of the candidates serves the whole call; `explain` runs
 * the same candidates and selection, so it cannot disagree with `resolveMany`. Reads only, no locks; time is the context's.
 */
#[Internal]
final readonly class DatabasePriceResolver implements PriceResolver
{
    public function __construct(
        private PriceCandidates $candidates,
        private Channels $channels,
        private PricingOptions $config,
        private ?PriceHistoryJournal $history = null,
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

        $winnersByIndex = [];
        $rankedByIndex = [];
        $discountedRequests = [];

        foreach ($requests as $index => $request) {
            $ranked = PriceSelection::rank($listed, (string) $request->sellable, $request->quantity, $context->customerGroups, $context->at);
            $winner = PriceSelection::winner($ranked);
            $winnersByIndex[$index] = $winner;
            $rankedByIndex[$index] = $ranked;

            if ($winner instanceof ListedPrice && $winner->compareAtMinor !== null) {
                $discountedRequests[$index] = [
                    'sellable' => (string) $request->sellable,
                    'quantity' => $request->quantity,
                    'winner' => $winner,
                ];
            }
        }

        $lowestByIndex = [];

        if ($discountedRequests !== []) {
            $lowestByIndex = $this->history !== null
                ? $this->history->lowestLast30Days(
                    discountedRequests: $discountedRequests,
                    channel: $context->channel,
                    currency: $context->currency,
                    customerGroups: $context->customerGroups,
                    at: $context->at,
                )
                : array_map(
                    static fn (array $entry): Money => Money::ofMinor($entry['winner']->amountMinor, $context->currency),
                    $discountedRequests,
                );
        }

        $decisions = [];

        foreach ($requests as $index => $request) {
            $winner = $winnersByIndex[$index];
            $ranked = $rankedByIndex[$index];

            if ($winner instanceof ListedPrice) {
                $decisions[] = [new ResolvedPrice(
                    unit: Money::ofMinor($winner->amountMinor, $context->currency),
                    compareAt: $winner->compareAtMinor === null ? null : Money::ofMinor($winner->compareAtMinor, $context->currency),
                    taxIncluded: $winner->taxIncluded ?? $channelTax(),
                    source: 'price_list:'.$winner->priceList,
                    lowestPriceLast30Days: $winner->compareAtMinor === null ? null : ($lowestByIndex[$index] ?? Money::ofMinor($winner->amountMinor, $context->currency)),
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
