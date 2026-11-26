<?php

declare(strict_types=1);

namespace Commerz\Ports\Taxes\Data;

use Commerz\Context\CommerceContext;
use Commerz\Errors\InvalidCommand;
use Commerz\Ports\Delivery\Data\Address;
use Packman\Attributes\Api;

/** Read-only tax calculation query for the `TaxEngine` port. */
#[Api(since: '0.1')]
final readonly class CalculateTax
{
    public ?string $buyerVatNumber;

    public ?string $sellerCountry;

    /**
     * @param  list<TaxableLine>  $lines
     */
    public function __construct(
        public CommerceContext $context,
        public ?Address $destination,
        public array $lines,
        public ?TaxExemption $exemption = null,
        ?string $buyerVatNumber = null,
        ?string $sellerCountry = null,
    ) {
        if ($lines === []) {
            throw new InvalidCommand('lines', 'must not be empty', 'taxes.invalid_request');
        }

        $keys = [];

        foreach ($lines as $line) {
            if (! $line->base->currency->equals($context->currency)) {
                throw new InvalidCommand('lines', "item {$line->key()} is not in {$context->currency->code}", 'taxes.invalid_request');
            }

            if (isset($keys[$line->key()])) {
                throw new InvalidCommand('lines', "item {$line->key()} appears twice", 'taxes.invalid_request');
            }

            $keys[$line->key()] = true;
        }

        $this->buyerVatNumber = $buyerVatNumber === null ? null : TaxExemption::normalizeVatNumber($buyerVatNumber, 'buyerVatNumber');
        $this->sellerCountry = $sellerCountry === null ? null : TaxExemption::normalizeCountry('sellerCountry', $sellerCountry);
    }
}
