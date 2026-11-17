<?php

declare(strict_types=1);

namespace Commerz\Shipping\Rates\Contracts;

use Commerz\Shipping\Rates\Data\RateInput;
use Commerz\Shipping\Rates\Data\RateParams;
use Commerz\Shipping\Rates\Data\RatePrice;
use Commerz\Shipping\Rates\Data\RateScope;
use Commerz\Shipping\Rates\Exceptions\InvalidRateConfig;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** How much a method charges for a parcel in a zone. */
#[Spi(kind: SpiKind::Implement, since: '0.1')]
interface DeliveryRate
{
    public const string KIND = 'delivery_rate';

    /** Contribution key, e.g. `flat` or `acme.zone_matrix`. */
    public function key(): string;

    /** The current version of the parameter schema, at least 1. */
    public function version(): int;

    /**
     * Validates the parameters and returns them in canonical form.
     *
     * @throws InvalidRateConfig
     */
    public function normalize(RateParams $params, RateScope $scope): RateParams;

    /**
     * Brings parameters stored at an older version to the current one.
     *
     * @throws InvalidRateConfig when they cannot be upgraded; the rate is then unsupported
     */
    public function upgrade(RateParams $params, int $fromVersion): RateParams;

    /** Does not write and makes no network call; time comes from `$input->request->context->at`. */
    public function price(RateInput $input): RatePrice;
}
