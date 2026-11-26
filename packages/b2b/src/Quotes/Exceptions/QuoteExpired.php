<?php

declare(strict_types=1);

namespace Commerz\B2b\Quotes\Exceptions;

use Commerz\B2b\Errors\B2bDomainException;
use Commerz\B2b\Errors\Enums\B2bErrorCode;
use Commerz\B2b\Quotes\Data\NegotiatedQuoteId;
use DateTimeImmutable;
use Packman\Attributes\Api;
use Packman\Errors\Enums\ErrorCategory;

#[Api(since: '0.1')]
final class QuoteExpired extends B2bDomainException
{
    public function __construct(
        public readonly NegotiatedQuoteId $quoteId,
        public readonly DateTimeImmutable $validUntil,
    ) {
        $formatted = $validUntil->format(DATE_ATOM);

        parent::__construct(
            b2bCode: B2bErrorCode::QuoteExpired,
            errorCategory: ErrorCategory::Conflict,
            message: "Negotiated quote '{$quoteId->value}' expired at {$formatted}.",
            contextDetails: [
                'quote_id' => $quoteId->value,
                'valid_until' => $formatted,
            ],
        );
    }
}
