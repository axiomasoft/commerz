<?php

declare(strict_types=1);

namespace Commerz\Stores\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Errors\Enums\CommerzErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

/** A store or channel code is already taken in this installation. */
#[Api(since: '0.1')]
final class DuplicateCode extends CommerzException
{
    public function __construct(public readonly string $entity, public readonly string $duplicate)
    {
        parent::__construct("{$entity} code '{$duplicate}' is already taken.");
    }

    public function code(): string
    {
        return 'stores.duplicate_code';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['entity' => $this->entity, 'code' => $this->duplicate];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: CommerzErrorCode::DuplicateCode,
            category: ErrorCategory::Conflict,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
