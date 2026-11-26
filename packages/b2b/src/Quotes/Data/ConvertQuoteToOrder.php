<?php

declare(strict_types=1);

namespace Commerz\B2b\Quotes\Data;

use Commerz\B2b\Approvals\Data\ApprovalFlowId;
use Commerz\B2b\PaymentTerms\Data\PaymentTerms;
use Commerz\Identity\ActorRef;
use Commerz\Identity\CustomerRef;
use Commerz\Identity\IdempotencyKey;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class ConvertQuoteToOrder
{
    public function __construct(
        public NegotiatedQuoteId $negotiatedQuoteId,
        public CustomerRef $buyer,
        public ?ApprovalFlowId $approvalFlowId = null,
        public ?PaymentTerms $paymentTerms = null,
        public ?IdempotencyKey $idempotencyKey = null,
        public ?ActorRef $actor = null,
    ) {}
}
