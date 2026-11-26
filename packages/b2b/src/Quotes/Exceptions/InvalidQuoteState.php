<?php

declare(strict_types=1);

namespace Commerz\B2b\Quotes\Exceptions;

use Commerz\B2b\Errors\B2bDomainException;
use Commerz\B2b\Errors\Enums\B2bErrorCode;
use Packman\Attributes\Api;
use Packman\Errors\Enums\ErrorCategory;

#[Api(since: '0.1')]
final class InvalidQuoteState extends B2bDomainException
{
    public function __construct(
        public readonly string $quoteId,
        public readonly string $status,
        public readonly string $reason,
    ) {
        parent::__construct(
            b2bCode: B2bErrorCode::InvalidQuoteState,
            errorCategory: ErrorCategory::Conflict,
            message: "Quote '{$quoteId}' in state '{$status}' cannot perform operation: {$reason}.",
            contextDetails: [
                'quote_id' => $quoteId,
                'status' => $status,
                'reason' => $reason,
            ],
        );
    }
}
