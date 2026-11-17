<?php

declare(strict_types=1);

namespace Commerz\Shipping\Rates\Internal;

use Commerz\Shipping\Rates\Contracts\DeliveryRate;
use Commerz\Shipping\Rates\Data\RateParams;
use Packman\Attributes\Internal;

/** A stored rate brought up to its plugin's current version, or the reason it cannot be: the method is then not offered. */
#[Internal]
final readonly class LoadedRate
{
    private function __construct(
        public ?DeliveryRate $rate,
        public ?RateParams $params,
        public ?string $reason,
    ) {}

    public static function loaded(DeliveryRate $rate, RateParams $params): self
    {
        return new self($rate, $params, null);
    }

    public static function unsupported(string $reason): self
    {
        return new self(null, null, $reason);
    }

    public function isSupported(): bool
    {
        return $this->rate !== null;
    }
}
