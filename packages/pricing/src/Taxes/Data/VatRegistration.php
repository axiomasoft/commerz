<?php

declare(strict_types=1);

namespace Commerz\Pricing\Taxes\Data;

use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Api;
use Stringable;

/**
 * Validated country-prefixed B2B VAT registration identifier (e.g. `DE123456789`, `FR12345678901`, `EL999999999`).
 * Evaluates cross-border B2B VAT Reverse Charge eligibility.
 */
#[Api(since: '0.1')]
final readonly class VatRegistration implements Stringable
{
    /** @var list<string> EU VAT union ISO-2 member territories (including `XI` Northern Ireland goods protocol) */
    public const array EU_COUNTRIES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI',
        'FR', 'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT',
        'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK', 'XI',
    ];

    public string $vatNumber;

    public string $country;

    public function __construct(string $vatNumber)
    {
        $cleaned = strtoupper((string) preg_replace('/[\s.\-]+/', '', trim($vatNumber)));

        if (preg_match('/\A([A-Z]{2})[A-Z0-9]{2,14}\z/', $cleaned, $matches) !== 1) {
            throw new InvalidCommand('vatNumber', "'{$vatNumber}' is not a valid country-prefixed VAT number", 'pricing.invalid_vat_number');
        }

        $prefix = $matches[1];
        $this->vatNumber = $cleaned;
        $this->country = $prefix === 'EL' ? 'GR' : $prefix;
    }

    public function isEuMember(): bool
    {
        return in_array($this->country, self::EU_COUNTRIES, true);
    }

    /**
     * Cross-border B2B VAT reverse charge applies when seller and destination countries are distinct,
     * the buyer's VAT registration belongs to the destination country, and either both are EU VAT territories
     * or cross-border B2B reverse charge is requested.
     */
    public function qualifiesForReverseCharge(string $sellerCountry, string $destinationCountry, bool $euOnly = true): bool
    {
        $seller = strtoupper(trim($sellerCountry));
        $destination = strtoupper(trim($destinationCountry));

        if (preg_match('/\A[A-Z]{2}\z/', $seller) !== 1 || preg_match('/\A[A-Z]{2}\z/', $destination) !== 1) {
            return false;
        }

        if ($seller === $destination) {
            return false;
        }

        if ($this->country !== $destination) {
            return false;
        }

        if ($euOnly) {
            return in_array($seller, self::EU_COUNTRIES, true) && $this->isEuMember();
        }

        return true;
    }

    public function __toString(): string
    {
        return $this->vatNumber;
    }
}
