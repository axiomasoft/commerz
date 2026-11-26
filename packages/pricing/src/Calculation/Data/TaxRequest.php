<?php

declare(strict_types=1);

namespace Commerz\Pricing\Calculation\Data;

use Commerz\Context\CommerceContext;
use Commerz\Errors\InvalidCommand;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Ports\Taxes\Data\TaxExemption;
use Commerz\Ports\Taxes\Enums\TaxExemptionType;
use Commerz\Pricing\Taxes\Data\VatRegistration;
use Packman\Attributes\Api;

/** What a tax calculator is asked: the items of one quote, in the quote's currency, plus optional B2B VAT / tax exemption claims. */
#[Api(since: '0.1')]
final readonly class TaxRequest
{
    public ?string $sellerCountry;

    /**
     * @param  Address|null  $destination  where the goods go; null without a delivery address (POS, digital goods)
     * @param  list<TaxableItem>  $items
     */
    public function __construct(
        public CommerceContext $context,
        public ?Address $destination,
        public array $items,
        public ?TaxExemption $exemption = null,
        public ?VatRegistration $buyerVat = null,
        ?string $sellerCountry = null,
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

        if ($sellerCountry !== null) {
            $cleaned = strtoupper(trim($sellerCountry));

            if (preg_match('/\A[A-Z]{2}\z/', $cleaned) !== 1) {
                throw new InvalidCommand('sellerCountry', "'{$sellerCountry}' must be an ISO 3166-1 alpha-2 country code", 'pricing.invalid_tax_request');
            }

            $this->sellerCountry = $cleaned;
        } else {
            $this->sellerCountry = null;
        }
    }

    public function isTaxExempt(): bool
    {
        return $this->exemption?->type === TaxExemptionType::Exempt;
    }

    public function isReverseCharge(): bool
    {
        if ($this->exemption?->type === TaxExemptionType::ReverseCharge) {
            $effectiveSeller = $this->sellerCountry ?? $this->exemption->sellerCountry;

            if ($effectiveSeller !== null && $this->destination !== null && $effectiveSeller === $this->destination->country) {
                return false;
            }

            $vatCountry = $this->exemption->vatCountryPrefix();

            if ($this->destination !== null && $vatCountry !== null && $vatCountry !== $this->destination->country) {
                return false;
            }

            return true;
        }

        if ($this->buyerVat instanceof VatRegistration && $this->destination instanceof Address && $this->sellerCountry !== null) {
            return $this->buyerVat->qualifiesForReverseCharge($this->sellerCountry, $this->destination->country);
        }

        return false;
    }
}
