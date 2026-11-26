<?php

declare(strict_types=1);

namespace Commerz\B2b\Quotes\Exceptions;

use Commerz\B2b\Errors\B2bDomainException;
use Commerz\B2b\Errors\Enums\B2bErrorCode;
use Commerz\B2b\Quotes\Data\NegotiatedQuoteId;
use Packman\Attributes\Api;
use Packman\Errors\Enums\ErrorCategory;

#[Api(since: '0.1')]
final class QuoteAlreadyConverted extends B2bDomainException
{
    public function __construct(
        public readonly NegotiatedQuoteId $quoteId,
        public readonly string $orderRef,
    ) {
        parent::__construct(
            b2bCode: B2bErrorCode::QuoteAlreadyConverted,
            errorCategory: ErrorCategory::Conflict,
            message: "Negotiated quote '{$quoteId->value}' has already been converted to '{$orderRef}'.",
            contextDetails: [
                'quote_id' => $quoteId->value,
                'order_ref' => $orderRef,
            ],
        );
    }
}
