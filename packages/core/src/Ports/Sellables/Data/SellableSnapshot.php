<?php

declare(strict_types=1);

namespace Commerz\Ports\Sellables\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;
use Commerz\Money\Money;
use Commerz\Ports\Sellables\Enums\SellableKind;

/** What a sale needs to know about a sellable at one moment, independent of the live owner. */
#[Api(since: '0.1')]
final readonly class SellableSnapshot
{
    /**
     * @param  string  $name  in the locale of the context it was resolved for
     * @param  array<string, string>  $options  display label => value, e.g. 'Colour' => 'Red'
     * @param  Money|null  $basePrice  the provider's own price (a CRM tariff); a price list overrides it
     * @param  array<string, scalar>  $attributes  facts for promotion conditions
     * @param  list<string>  $categories
     */
    public function __construct(
        public SellableRef $ref,
        public string $sku,
        public string $name,
        public SellableKind $kind,
        public bool $requiresShipping,
        public bool $purchasable = true,
        public ?string $unavailableReason = null,
        public array $options = [],
        public ?Money $basePrice = null,
        public ?string $taxClass = null,
        public ?int $weightGrams = null,
        public array $attributes = [],
        public array $categories = [],
    ) {
        if ($sku === '' || strlen($sku) > 191) {
            throw new InvalidCommand('sku', 'must be 1..191 bytes', 'sellables.invalid_snapshot');
        }

        if (trim($name) === '') {
            throw new InvalidCommand('name', 'must not be blank', 'sellables.invalid_snapshot');
        }

        if ($basePrice?->isNegative() === true) {
            throw new InvalidCommand('basePrice', 'must not be negative', 'sellables.invalid_snapshot');
        }

        if ($weightGrams !== null && $weightGrams < 0) {
            throw new InvalidCommand('weightGrams', 'must not be negative', 'sellables.invalid_snapshot');
        }

        if ($purchasable && $unavailableReason !== null) {
            throw new InvalidCommand('unavailableReason', 'is set only when the sellable is not purchasable', 'sellables.invalid_snapshot');
        }
    }
}
