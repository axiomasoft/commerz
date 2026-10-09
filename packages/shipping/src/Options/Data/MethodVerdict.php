<?php

declare(strict_types=1);

namespace Commerz\Shipping\Options\Data;

use Commerz\Attributes\Api;
use Commerz\Money\Money;

/**
 * Whether one method delivers to the request and why not. `$verdict` is a stable snake_case code: `available`,
 * `method_not_found`, `method_disabled`, `not_in_channel`, `unsupported_carrier`, `no_zone`, `no_rate_for_currency`,
 * `unsupported_rate`, `invalid_rate_result`, or the reason a rate gave (e.g. `weight_out_of_range`). `$zoneCode` is
 * the zone whose tariff was chosen; `$price` is set only when the method is available.
 */
#[Api(since: '0.1')]
final readonly class MethodVerdict
{
    public const string AVAILABLE = 'available';

    public function __construct(
        public string $methodCode,
        public string $verdict,
        public string $message,
        public ?string $zoneCode = null,
        public ?Money $price = null,
    ) {}

    public function isAvailable(): bool
    {
        return $this->verdict === self::AVAILABLE;
    }
}
