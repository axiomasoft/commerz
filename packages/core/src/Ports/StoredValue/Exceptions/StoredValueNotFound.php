<?php

declare(strict_types=1);

namespace Commerz\Ports\StoredValue\Exceptions;

use Commerz\Errors\Enums\CommerzErrorCode;
use Commerz\Errors\NotFound;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

/** A referenced stored-value account or hold does not exist. */
#[Api(since: '0.1')]
final class StoredValueNotFound extends NotFound
{
    public function __construct(string $entity, string $id)
    {
        parent::__construct($entity, $id, 'stored_value.not_found');
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: CommerzErrorCode::StoredValueNotFound,
            category: ErrorCategory::NotFound,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
