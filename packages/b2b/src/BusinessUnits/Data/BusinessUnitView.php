<?php

declare(strict_types=1);

namespace Commerz\B2b\BusinessUnits\Data;

use Commerz\B2b\BusinessUnits\Enums\BusinessUnitStatus;
use Commerz\Money\Currency;
use Commerz\Ports\Delivery\Data\Address;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class BusinessUnitView
{
    public function __construct(
        public BusinessUnitId $id,
        public string $code,
        public string $name,
        public ?BusinessUnitId $parentId,
        public BusinessUnitId $rootId,
        public string $materializedPath,
        public int $depth,
        public BusinessUnitStatus $status,
        public Currency $defaultCurrency,
        public ?string $taxId,
        public ?string $defaultPaymentTermsCode,
        public ?Address $billingAddress,
        public ?Address $shippingAddress,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}

    /**
     * Ancestor IDs from root down to immediate parent (excluding self).
     *
     * @return list<BusinessUnitId>
     */
    public function ancestorIds(): array
    {
        $parts = array_values(array_filter(explode('/', trim($this->materializedPath, '/')), static fn (string $s): bool => $s !== ''));
        $ancestors = [];

        foreach ($parts as $part) {
            if ($part !== $this->id->value) {
                $ancestors[] = BusinessUnitId::fromString($part);
            }
        }

        return $ancestors;
    }
}
