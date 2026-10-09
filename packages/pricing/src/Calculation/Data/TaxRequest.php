<?php

declare(strict_types=1);

namespace Commerz\Pricing\Calculation\Data;

use Commerz\Attributes\Api;
use Commerz\Context\CommerceContext;
use Commerz\Errors\InvalidCommand;
use Commerz\Ports\Delivery\Data\Address;

/** What a tax calculator is asked: the items of one quote, in the quote's currency. */
#[Api(since: '0.1')]
final readonly class TaxRequest
{
    /**
     * @param  Address|null  $destination  where the goods go; null without a delivery address (POS, digital goods)
     * @param  list<TaxableItem>  $items
     */
    public function __construct(
        public CommerceContext $context,
        public ?Address $destination,
        public array $items,
    ) {
        if ($items === []) {
            throw new InvalidCommand('items', 'must not be empty', 'pricing.invalid_tax_request');
        }

        $keys = [];

        foreach ($items as $item) {
            if (! $item->base->currency->equals($context->currency)) {
                throw new InvalidCommand('items', "item {$item->key()} is not in {$context->currency->code}", 'pricing.invalid_tax_request');
            }

            if (isset($keys[$item->key()])) {
                throw new InvalidCommand('items', "item {$item->key()} appears twice", 'pricing.invalid_tax_request');
            }

            $keys[$item->key()] = true;
        }
    }
}
