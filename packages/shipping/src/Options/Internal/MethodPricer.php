<?php

declare(strict_types=1);

namespace Commerz\Shipping\Options\Internal;

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Ports\Delivery\Data\DeliveryRequest;
use Commerz\Shipping\Carriers\Contracts\Carrier;
use Commerz\Shipping\Methods\Enums\MethodStatus;
use Commerz\Shipping\Options\Data\MethodVerdict;
use Commerz\Shipping\Rates\Data\RateInput;
use Commerz\Shipping\Rates\Data\RateScope;
use Commerz\Shipping\Rates\Internal\RateCodec;
use Packman\Attributes\Internal;
use Packman\Plugins\Contracts\Contributions\Contributions;
use Throwable;

/**
 * The only price of a delivery method (SHP-01): the options, their explanation and the delivery stage of a quote all
 * ask it, so the same request costs the same everywhere. Each method gets one verdict, decided by the steps of
 * knowledge/shipping-design#pricing in this order: disabled, not in the channel, carrier not installed, no matching
 * zone, no tariff in the context's currency, then the tariff of the most specific zone (highest rank, then zone code)
 * is decoded and priced, and `free_above` is applied to `$request->parcel->value`. A rate plugin that throws or
 * answers in another currency makes its method `invalid_rate_result`; nothing it does escapes. Pure: reads only.
 */
#[Internal]
final readonly class MethodPricer
{
    public function __construct(
        private CandidateRates $candidates,
        private RateCodec $codec,
        private Contributions $contributions,
    ) {}

    /**
     * @param  string|null  $onlyMethodCode  price just this method; its verdict is `method_not_found` when it is
     *                                       missing or archived
     * @return list<PricedMethod> one per method that is not archived, by method code
     *
     * @throws InvalidCommand shipping.currency_mismatch when the parcel value is not in the context's currency
     */
    public function price(DeliveryRequest $request, ?string $onlyMethodCode = null): array
    {
        $currency = $request->context->currency;

        if (! $request->parcel->value->currency->equals($currency)) {
            throw new InvalidCommand('parcel.value', "{$request->parcel->value->currency->code} differs from the context currency {$currency->code}", 'shipping.currency_mismatch');
        }

        $byMethod = [];

        foreach ($this->candidates->load($request->context, $request->destination, $onlyMethodCode) as $candidate) {
            $byMethod[$candidate->methodCode][] = $candidate;
        }

        if ($onlyMethodCode !== null && $byMethod === []) {
            return [PricedMethod::notFound($onlyMethodCode)];
        }

        ksort($byMethod, SORT_STRING);

        return array_values(array_map(fn (array $candidates): PricedMethod => $this->priceMethod($request, $currency, $candidates), $byMethod));
    }

    /** @param  non-empty-list<CandidateRate>  $candidates  the tariffs of one method */
    private function priceMethod(DeliveryRequest $request, Currency $currency, array $candidates): PricedMethod
    {
        $method = $candidates[0];
        $verdict = fn (string $verdict, string $message, ?CandidateRate $rate = null): PricedMethod => new PricedMethod(
            $method->methodCode,
            $method->name,
            $method->carrier,
            $method->position,
            $verdict,
            $message,
            $rate?->zoneCode,
            taxIncluded: $rate !== null && $rate->taxIncluded,
            etaDays: $rate?->etaDays,
        );

        if ($method->status === MethodStatus::Disabled) {
            return $verdict('method_disabled', 'the method is disabled');
        }

        if (! $method->inChannel) {
            return $verdict('not_in_channel', "the method is not offered in channel {$request->context->channel->value}");
        }

        if (! $this->contributions->has(Carrier::KIND, $method->carrier)) {
            return $verdict('unsupported_carrier', "no carrier contributes '{$method->carrier}'");
        }

        $matched = array_values(array_filter($candidates, static fn (CandidateRate $rate): bool => $rate->rateId !== null && $rate->rank !== null));

        if ($matched === []) {
            return $verdict('no_zone', 'no zone of the method\'s tariffs covers the address');
        }

        $inCurrency = array_values(array_filter($matched, static fn (CandidateRate $rate): bool => $rate->currency === $currency->code));

        if ($inCurrency === []) {
            return $verdict('no_rate_for_currency', "the zones covering the address have no tariff in {$currency->code}");
        }

        // SHP-06: the most specific zone wins, a tie goes to the lower zone code, never to the order of rows.
        usort($inCurrency, static function (CandidateRate $a, CandidateRate $b): int {
            $byRank = $b->rank <=> $a->rank;

            return $byRank !== 0 ? $byRank : strcmp((string) $a->zoneCode, (string) $b->zoneCode);
        });
        $rate = $inCurrency[0];

        $loaded = $this->codec->decodeRow((string) $rate->rateKey, (int) $rate->rateVersion, $rate->params, new RateScope($currency));

        if ($loaded->rate === null || $loaded->params === null) {
            return $verdict('unsupported_rate', (string) $loaded->reason, $rate);
        }

        try {
            $price = $this->codec->assertPrice(
                (string) $rate->rateKey,
                $loaded->rate->price(new RateInput($request, $loaded->params, $currency, (string) $rate->zoneCode)),
                $currency,
            );
        } catch (Throwable $e) {
            return $verdict('invalid_rate_result', "rate '{$rate->rateKey}' failed: {$e->getMessage()}", $rate);
        }

        if ($price->amount === null) {
            return $verdict((string) $price->reason, (string) $price->message, $rate);
        }

        $free = $rate->freeAboveMinor !== null && $request->parcel->value->amountMinor >= $rate->freeAboveMinor;

        return new PricedMethod(
            $method->methodCode,
            $method->name,
            $method->carrier,
            $method->position,
            MethodVerdict::AVAILABLE,
            $free ? 'free above the threshold' : 'available',
            $rate->zoneCode,
            $free ? Money::zero($currency) : $price->amount,
            $rate->taxIncluded,
            $rate->etaDays,
            $free,
        );
    }
}
