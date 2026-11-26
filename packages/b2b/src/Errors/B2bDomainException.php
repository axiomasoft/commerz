<?php

declare(strict_types=1);

namespace Commerz\B2b\Errors;

use Commerz\B2b\Errors\Enums\B2bErrorCode;
use Commerz\Errors\CommerzException;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

/**
 * Base exception for structured domain errors emitted by `axiomasoft/commerz-b2b`.
 */
#[Api(since: '0.1')]
abstract class B2bDomainException extends CommerzException
{
    /** @param array<string, scalar|null> $contextDetails */
    public function __construct(
        private readonly B2bErrorCode $b2bCode,
        private readonly ErrorCategory $errorCategory,
        string $message,
        private readonly array $contextDetails = [],
        private readonly ?string $pointerPath = null,
    ) {
        parent::__construct($message);
    }

    #[Override]
    public function code(): string
    {
        return $this->b2bCode->value;
    }

    /** @return array<string, scalar|null> */
    #[Override]
    public function context(): array
    {
        return $this->contextDetails;
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: $this->b2bCode,
            category: $this->errorCategory,
            retryable: false,
            path: $this->pointerPath,
            details: self::sanitizeDetails($this->contextDetails),
        );
    }
}
