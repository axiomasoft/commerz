<?php

declare(strict_types=1);

namespace Commerz\Shipping\Rates\Internal;

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Currency;
use Commerz\Shipping\Rates\Contracts\DeliveryRate;
use Commerz\Shipping\Rates\Data\RateParams;
use Commerz\Shipping\Rates\Data\RatePrice;
use Commerz\Shipping\Rates\Data\RateScope;
use Commerz\Shipping\Rates\Data\RateSpec;
use Commerz\Shipping\Rates\Exceptions\InvalidRateConfig;
use Commerz\Shipping\Rates\Exceptions\InvalidRateResult;
use Packman\Attributes\Internal;
use Packman\Plugins\Contracts\Contributions\Contributions;
use Packman\Plugins\Exceptions\Registry\UnsupportedContribution;
use Throwable;

/**
 * The only way a rate goes into and comes out of storage. Writing is strict: an unknown key or wrong parameters
 * refuse the command. Reading never throws on stored data: a rate that cannot be loaded (no plugin, a newer version
 * than the plugin knows, an upgrade that fails) is `unsupported` and its method is not offered in that zone.
 */
#[Internal]
final readonly class RateCodec
{
    public function __construct(private Contributions $contributions) {}

    /**
     * @param  string  $path  the field of the command the rate came from, e.g. `rates.0.rate`
     *
     * @throws InvalidCommand shipping.unknown_rate, shipping.invalid_rate_config
     */
    public function encode(RateSpec $spec, RateScope $scope, string $path): StoredRate
    {
        if (! $this->contributions->has(DeliveryRate::KIND, $spec->key)) {
            throw new InvalidCommand("{$path}.key", "no rate contributes '{$spec->key}'", 'shipping.unknown_rate');
        }

        try {
            $rate = $this->contributions->get(DeliveryRate::KIND, $spec->key, DeliveryRate::class);
        } catch (UnsupportedContribution) {
            throw new InvalidCommand("{$path}.key", "'{$spec->key}' is not a delivery rate", 'shipping.unknown_rate');
        }

        $version = $rate->version();

        if ($version < 1) {
            throw new InvalidRateResult($spec->key, 'version() must be at least 1');
        }

        try {
            $params = $rate->normalize(RateParams::fromInput($spec->params), $scope);
        } catch (InvalidRateConfig $e) {
            throw new InvalidCommand("{$path}.params.{$e->field}", $e->reason, 'shipping.invalid_rate_config');
        }

        return new StoredRate($spec->key, $version, $params);
    }

    /** Never throws on stored data. */
    public function decode(StoredRate $stored, RateScope $scope): LoadedRate
    {
        if (! $this->contributions->has(DeliveryRate::KIND, $stored->key)) {
            return LoadedRate::unsupported("no rate contributes '{$stored->key}'");
        }

        try {
            $rate = $this->contributions->get(DeliveryRate::KIND, $stored->key, DeliveryRate::class);
            $current = $rate->version();

            if ($current < 1) {
                return LoadedRate::unsupported('the plugin version must be at least 1');
            }

            if ($stored->version > $current) {
                return LoadedRate::unsupported("stored at version {$stored->version}, the plugin knows {$current}");
            }

            $params = $stored->params;

            if ($stored->version < $current) {
                $params = $rate->upgrade($params, $stored->version);
            }

            return LoadedRate::loaded($rate, $rate->normalize($params, $scope));
        } catch (InvalidRateConfig $e) {
            return LoadedRate::unsupported("stored parameters are invalid: {$e->getMessage()}");
        } catch (Throwable $e) {
            return LoadedRate::unsupported("rate '{$stored->key}' could not be loaded: {$e->getMessage()}");
        }
    }

    /**
     * Decodes a row's raw columns; malformed stored parameters are unsupported like any other stored defect.
     *
     * @param  array<array-key, mixed>  $params  the decoded `params` column
     */
    public function decodeRow(string $key, int $version, array $params, RateScope $scope): LoadedRate
    {
        try {
            return $this->decode(new StoredRate($key, $version, RateParams::fromStored($params)), $scope);
        } catch (InvalidRateConfig $e) {
            return LoadedRate::unsupported("stored parameters are invalid: {$e->getMessage()}");
        }
    }

    /** @throws InvalidRateResult a price in a currency other than the tariff's */
    public function assertPrice(string $key, RatePrice $price, Currency $currency): RatePrice
    {
        if ($price->amount !== null && $price->amount->currency->code !== $currency->code) {
            throw new InvalidRateResult($key, "the price is in {$price->amount->currency->code}, the tariff is in {$currency->code}");
        }

        return $price;
    }
}
