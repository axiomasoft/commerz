<?php

declare(strict_types=1);

namespace Commerz\B2b\Quotes\Data;

use Commerz\B2b\BusinessUnits\Data\BusinessUnitId;
use Commerz\B2b\PaymentTerms\Data\PaymentTerms;
use Commerz\B2b\Quotes\Enums\QuoteRequestStatus;
use Commerz\Context\CommerceContext;
use Commerz\Identity\CustomerRef;
use Commerz\Ports\Delivery\Data\Address;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class QuoteRequestView
{
    /**
     * @param  list<QuoteRequestLineView>  $lines
     * @param  list<string>  $codes
     */
    public function __construct(
        public QuoteRequestId $id,
        public string $number,
        public BusinessUnitId $businessUnitId,
        public CustomerRef $buyer,
        public CommerceContext $context,
        public ?string $sourceCartId,
        public array $lines,
        public ?PaymentTerms $requestedPaymentTerms,
        public ?string $purchaseOrderNumber,
        public ?string $buyerNote,
        public ?Address $shippingAddress,
        public ?Address $billingAddress,
        public ?string $deliveryMethod,
        public array $codes,
        public QuoteRequestStatus $status,
        public int $revision,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
