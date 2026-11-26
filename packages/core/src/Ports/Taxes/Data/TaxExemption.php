<?php

declare(strict_types=1);

namespace Commerz\Ports\Taxes\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Ports\Taxes\Enums\TaxExemptionType;
use Packman\Attributes\Api;

/**
 * Validated tax exemption or B2B VAT Reverse Charge claim attached to a tax evaluation request.
 */
#[Api(since: '0.1')]
final readonly class TaxExemption
{
    public ?string $vatNumber;

    public ?string $sellerCountry;

    public ?string $certificateRef;

    public ?string $reason;

    public function __construct(
        public TaxExemptionType $type,
        ?string $vatNumber = null,
        ?string $sellerCountry = null,
        ?string $certificateRef = null,
        ?string $reason = null,
    ) {
        $normalizedVat = $vatNumber === null ? null : self::normalizeVatNumber($vatNumber);
        $normalizedSeller = $sellerCountry === null ? null : self::normalizeCountry('sellerCountry', $sellerCountry);

        if ($type === TaxExemptionType::ReverseCharge && $normalizedVat === null) {
            throw new InvalidCommand('vatNumber', 'reverse charge requires a valid B2B VAT number', 'taxes.invalid_exemption');
        }

        if ($certificateRef !== null && (trim($certificateRef) === '' || strlen($certificateRef) > 128)) {
            throw new InvalidCommand('certificateRef', 'must be 1 to 128 bytes', 'taxes.invalid_exemption');
        }

        if ($reason !== null && (trim($reason) === '' || strlen($reason) > 255)) {
            throw new InvalidCommand('reason', 'must be 1 to 255 bytes', 'taxes.invalid_exemption');
        }

        $this->vatNumber = $normalizedVat;
        $this->sellerCountry = $normalizedSeller;
        $this->certificateRef = $certificateRef === null ? null : trim($certificateRef);
        $this->reason = $reason === null ? null : trim($reason);
    }

    public static function exempt(?string $reason = null, ?string $certificateRef = null): self
    {
        return new self(TaxExemptionType::Exempt, certificateRef: $certificateRef, reason: $reason);
    }

    public static function reverseCharge(string $vatNumber, ?string $sellerCountry = null, ?string $reason = null): self
    {
        return new self(TaxExemptionType::ReverseCharge, vatNumber: $vatNumber, sellerCountry: $sellerCountry, reason: $reason);
    }

    public static function normalizeVatNumber(string $vatNumber, string $field = 'vatNumber'): string
    {
        $cleaned = strtoupper((string) preg_replace('/[\s.\-]+/', '', trim($vatNumber)));

        if (preg_match('/\A[A-Z]{2}[A-Z0-9]{2,14}\z/', $cleaned) !== 1) {
            throw new InvalidCommand($field, "'{$vatNumber}' is not a valid country-prefixed VAT number", 'taxes.invalid_vat_number');
        }

        return $cleaned;
    }

    public static function normalizeCountry(string $field, string $country): string
    {
        $cleaned = strtoupper(trim($country));

        if (preg_match('/\A[A-Z]{2}\z/', $cleaned) !== 1) {
            throw new InvalidCommand($field, "'{$country}' must be an ISO 3166-1 alpha-2 country code", 'taxes.invalid_country');
        }

        return $cleaned;
    }

    public function vatCountryPrefix(): ?string
    {
        if ($this->vatNumber === null) {
            return null;
        }

        $prefix = substr($this->vatNumber, 0, 2);

        return $prefix === 'EL' ? 'GR' : $prefix;
    }
}
