<?php

declare(strict_types=1);

namespace Commerz\Ports\Delivery\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;
use Commerz\Ports\Delivery\Enums\DeliveryMode;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class DeliveryOption
{
    /** @param  array<string, scalar>  $meta  carrier details for display, e.g. pickup point id */
    public function __construct(
        public string $methodCode,
        public string $name,
        public Money $price,
        public string $carrier,
        public ?int $etaDays = null,
        public array $meta = [],
        public DeliveryMode $mode = DeliveryMode::Shipping,
    ) {
        if ($methodCode === '' || $carrier === '') {
            throw new InvalidCommand('methodCode', 'method code and carrier must not be empty', 'delivery.invalid_option');
        }

        if ($price->isNegative()) {
            throw new InvalidCommand('price', 'must not be negative', 'delivery.invalid_option');
        }

        if ($etaDays !== null && $etaDays < 0) {
            throw new InvalidCommand('etaDays', 'must not be negative', 'delivery.invalid_option');
        }
    }
}
