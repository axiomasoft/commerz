<?php

declare(strict_types=1);

namespace Commerz\Shipping\Options\Internal;

use Commerz\Measures\LocalizedText;
use Commerz\Money\Money;
use Commerz\Ports\Delivery\Data\DeliveryOption;
use Commerz\Shipping\Options\Data\MethodVerdict;
use LogicException;

/**
 * The verdict of one method for a delivery request. `$price` is set only when it is available; `$name` and `$carrier`
 * are null only for `method_not_found`. `$freeAbove` says the price is zero because the goods reached the threshold.
 */
final readonly class PricedMethod
{
    public function __construct(
        public string $methodCode,
        public ?LocalizedText $name,
        public ?string $carrier,
        public int $position,
        public string $verdict,
        public string $message,
        public ?string $zoneCode = null,
        public ?Money $price = null,
        public bool $taxIncluded = false,
        public ?int $etaDays = null,
        public bool $freeAbove = false,
    ) {}

    public static function notFound(string $methodCode): self
    {
        return new self($methodCode, null, null, 0, 'method_not_found', "no shipping method '{$methodCode}' that is not archived");
    }

    public function isAvailable(): bool
    {
        return $this->verdict === MethodVerdict::AVAILABLE && $this->price !== null;
    }

    /** The method as a priced delivery option, its name in `$locale`. */
    public function option(string $locale): DeliveryOption
    {
        if (! $this->isAvailable() || $this->price === null || $this->name === null || $this->carrier === null) {
            throw new LogicException("Shipping method '{$this->methodCode}' is not available ({$this->verdict}).");
        }

        return new DeliveryOption(
            $this->methodCode,
            $this->name->resolve($locale),
            $this->price,
            $this->carrier,
            $this->etaDays,
            ['zone' => (string) $this->zoneCode, 'free_above' => $this->freeAbove, 'tax_included' => $this->taxIncluded],
        );
    }

    public function verdict(): MethodVerdict
    {
        return new MethodVerdict($this->methodCode, $this->verdict, $this->message, $this->zoneCode, $this->price);
    }
}
