<?php

declare(strict_types=1);

namespace Commerz\B2b\Quotes\Exceptions;

use Commerz\B2b\Errors\B2bDomainException;
use Commerz\B2b\Errors\Enums\B2bErrorCode;
use DateTimeImmutable;
use Packman\Attributes\Api;
use Packman\Errors\Enums\ErrorCategory;

#[Api(since: '0.1')]
final class InvalidQuoteValidity extends B2bDomainException
{
    public function __construct(public readonly DateTimeImmutable $validUntil)
    {
        $formatted = $validUntil->format(DATE_ATOM);

        parent::__construct(
            b2bCode: B2bErrorCode::InvalidQuoteValidity,
            errorCategory: ErrorCategory::Validation,
            message: "Quote validUntil ({$formatted}) must be in the future.",
            contextDetails: ['valid_until' => $formatted],
            pointerPath: '/valid_until',
        );
    }
}
