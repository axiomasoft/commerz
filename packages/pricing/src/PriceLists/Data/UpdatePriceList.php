<?php

declare(strict_types=1);

namespace Commerz\Pricing\PriceLists\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Pricing\PriceLists\Enums\TaxInclusion;
use Packman\Attributes\Api;

/** Renames a price list or changes its priority, tax inclusion, parent/formula, or rounding rule; a field left null stays. Code and currency never change. */
#[Api(since: '0.1')]
final readonly class UpdatePriceList
{
    public function __construct(
        public PriceListId $priceList,
        public ?string $name = null,
        public ?int $priority = null,
        public ?TaxInclusion $taxInclusion = null,
        public ?PriceListId $parentPriceListId = null,
        public ?PriceListFormula $formula = null,
        public ?RoundingRule $roundingRule = null,
        public bool $clearParent = false,
        public bool $clearRoundingRule = false,
    ) {
        if ($name !== null) {
            CreatePriceList::assertName($name);
        }

        if ($priority !== null) {
            CreatePriceList::assertPriority($priority);
        }

        if ($clearParent && ($parentPriceListId instanceof PriceListId || $formula instanceof PriceListFormula)) {
            throw new InvalidCommand('clearParent', 'cannot clear parent while setting parentPriceListId or formula', 'pricing.invalid_formula');
        }

        if ($clearRoundingRule && $roundingRule instanceof RoundingRule) {
            throw new InvalidCommand('clearRoundingRule', 'cannot clear and set roundingRule simultaneously', 'pricing.invalid_rounding_rule');
        }
    }
}
