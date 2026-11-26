<?php

declare(strict_types=1);

namespace Commerz\B2b\Quotes\Data;

use Commerz\B2b\BusinessUnits\Data\BusinessUnitId;
use Commerz\B2b\PaymentTerms\Data\PaymentSchedule;
use Commerz\B2b\PaymentTerms\Data\PaymentTerms;
use Commerz\Context\CommerceContext;
use Commerz\Identity\ActorRef;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Quotes\Data\QuoteTotals;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class PlaceLockedQuoteOrder
{
    /**
     * @param  list<NegotiatedQuoteLineView>  $lines
     * @param  list<string>  $codes
     */
    public function __construct(
        public NegotiatedQuoteId $negotiatedQuoteId,
        public BusinessUnitId $businessUnitId,
        public CommerceContext $context,
        public array $lines,
        public QuoteTotals $lockedTotals,
        public ?PaymentTerms $paymentTerms,
        public ?PaymentSchedule $paymentSchedule,
        public ?Address $shippingAddress,
        public ?Address $billingAddress,
        public ?string $deliveryMethod,
        public array $codes,
        public ?ActorRef $actor,
        public DateTimeImmutable $placedAt,
    ) {}
}
