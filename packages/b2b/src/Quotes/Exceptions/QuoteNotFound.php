<?php

declare(strict_types=1);

namespace Commerz\B2b\Quotes\Exceptions;

use Commerz\B2b\Errors\B2bDomainException;
use Commerz\B2b\Errors\Enums\B2bErrorCode;
use Packman\Attributes\Api;
use Packman\Errors\Enums\ErrorCategory;

#[Api(since: '0.1')]
final class QuoteNotFound extends B2bDomainException
{
    public function __construct(
        public readonly string $entity,
        public readonly string $identifier,
    ) {
        parent::__construct(
            b2bCode: B2bErrorCode::QuoteNotFound,
            errorCategory: ErrorCategory::NotFound,
            message: "{$entity} '{$identifier}' was not found.",
            contextDetails: ['entity' => $entity, 'id' => $identifier],
        );
    }
}
