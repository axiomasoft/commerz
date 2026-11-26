<?php

declare(strict_types=1);

namespace Commerz\B2b\BusinessUnits\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Currency;
use Commerz\Ports\Delivery\Data\Address;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class CreateBusinessUnit
{
    public string $code;

    public string $name;

    public function __construct(
        string $code,
        string $name,
        public Currency $defaultCurrency,
        public ?BusinessUnitId $parentId = null,
        public ?string $taxId = null,
        public ?string $defaultPaymentTermsCode = null,
        public ?Address $billingAddress = null,
        public ?Address $shippingAddress = null,
    ) {
        $normalizedCode = strtolower(trim($code));

        if (preg_match('/\A[a-z0-9][a-z0-9_\-]{0,63}\z/', $normalizedCode) !== 1) {
            throw new InvalidCommand('code', "'{$code}' must match [a-z0-9][a-z0-9_-]{0,63}", 'b2b.invalid_business_unit');
        }

        $trimmedName = trim($name);

        if ($trimmedName === '' || strlen($trimmedName) > 191) {
            throw new InvalidCommand('name', 'must be 1..191 bytes', 'b2b.invalid_business_unit');
        }

        if ($taxId !== null && (trim($taxId) === '' || strlen($taxId) > 64)) {
            throw new InvalidCommand('taxId', 'must be 1..64 bytes when provided', 'b2b.invalid_business_unit');
        }

        if ($defaultPaymentTermsCode !== null && (trim($defaultPaymentTermsCode) === '' || strlen($defaultPaymentTermsCode) > 64)) {
            throw new InvalidCommand('defaultPaymentTermsCode', 'must be 1..64 bytes when provided', 'b2b.invalid_business_unit');
        }

        $this->code = $normalizedCode;
        $this->name = $trimmedName;
    }
}
